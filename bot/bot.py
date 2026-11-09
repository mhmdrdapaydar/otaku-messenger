#!/usr/bin/env python3
"""ربات تلگرام (pyTelegramBotAPI + Polling) — کیف پول هات BEP20.

قابلیت‌ها (نسخهٔ فعلی):
  /start   آشنایی + پنل دکمه‌های مدیر
  /help    راهنمای دستورات
  /balance موجودی USDT (BEP20) و BNB کیف پول هات   [فقط ادمین]
  /charge  نمایش آدرس + QR برای شارژ کیف پول هات    [فقط ادمین]

اجرا:
  pip install -r requirements.txt
  python bot.py
"""

import functools
import io
import logging
import time
from datetime import datetime, timezone

import telebot
from telebot import types

import config
import wallet

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)-7s | %(name)s | %(message)s",
)
logger = logging.getLogger("otaku-bot")

try:
    import segno
    QR_AVAILABLE = True
except ImportError:  # pragma: no cover
    segno = None
    QR_AVAILABLE = False
    logger.warning("پکیج segno نصب نیست؛ QR کد ارسال نمی‌شود (فقط متن).")

if not config.BOT_TOKEN:
    raise SystemExit(
        "❌ BOT_TOKEN تنظیم نشده است. فایل bot/.env را بسازید "
        "(cp .env.example .env) و توکن BotFather را داخل آن قرار دهید."
    )

bot = telebot.TeleBot(config.BOT_TOKEN, parse_mode="HTML")

# ── ابزارهای کمکی ────────────────────────────────────────────────


def _is_admin_id(user_id) -> bool:
    return user_id is not None and int(user_id) == config.ADMIN_ID


def admin_required(handler):
    """فقط پیام‌های ادمین (config.ADMIN_ID) را به handler می‌دهد."""

    @functools.wraps(handler)
    def wrapper(message):
        user = message.from_user
        if _is_admin_id(getattr(user, "id", None)):
            return handler(message)
        logger.warning("⛔️ درخواست غیرمجاز از user_id=%s", getattr(user, "id", "?"))
        bot.reply_to(message, "⛔️ شما دسترسی به این ربات را ندارید.")

    return wrapper


def _bscscan_url() -> str:
    return config.BSCSCAN_ADDRESS_URL.format(config.WALLET_ADDRESS)


def _admin_keyboard() -> types.InlineKeyboardMarkup:
    kb = types.InlineKeyboardMarkup(row_width=2)
    kb.add(
        types.InlineKeyboardButton("💼 موجودی کیف پول", callback_data="cmd:balance"),
        types.InlineKeyboardButton("💳 شارژ کیف پول", callback_data="cmd:charge"),
    )
    kb.add(
        types.InlineKeyboardButton("🔍 مشاهده در BscScan", url=_bscscan_url())
    )
    return kb


def _balance_text(bal: dict) -> str:
    updated = datetime.fromtimestamp(bal["updated_at"], tz=timezone.utc)
    updated_fa = updated.strftime("%Y-%m-%d %H:%M:%S UTC")
    return (
        f"💼 <b>موجودی کیف پول هات</b>\n"
        f"{config.WALLET_CHAIN_EMOJI} شبکه: <b>{config.WALLET_CHAIN}</b>\n"
        f"آدرس: <code>{bal['address']}</code>\n\n"
        f"💵 {config.USDT_SYMBOL} (BEP20): <b>{bal['usdt']:,.2f}</b>\n"
        f"🪙 BNB: <b>{bal['bnb']:.6f}</b>  <i>(برای کارمزد شبکه)</i>\n\n"
        f"🕒 بروزرسانی: {updated_fa}"
    )


def _charge_text() -> str:
    return (
        f"💳 <b>شارژ کیف پول هات</b>\n"
        f"{config.WALLET_CHAIN_EMOJI} شبکه: <b>{config.WALLET_CHAIN}</b>\n\n"
        f"برای شارژ، دارایی را فقط از بستر <b>BEP20 (بایننس اسمارت چین)</b> به این آدرس بفرستید:\n\n"
        f"<code>{config.WALLET_ADDRESS}</code>\n\n"
        f"💵 قرارداد {config.USDT_SYMBOL}: <code>{config.USDT_BEP20_CONTRACT}</code>\n"
        f"🔢 اعشار USDT: <b>{config.USDT_DECIMALS}</b>\n\n"
        f"⚠️ <b>توجه:</b>\n"
        f"• ارسال روی شبکهٔ غیر از BEP20 (مثلاً ERC20 یا TRC20) باعث از دست رفتن دارایی می‌شود.\n"
        f"• این آدرس بدون ممو/تگ است؛ فقط آدرس را وارد کنید.\n"
        f"• برای برداشت/انتقال بعدی به مقداری BNB برای کارمزد نیاز است."
    )


def _qr_png_bytes(text: str) -> bytes:
    buf = io.BytesIO()
    buf.name = "hot-wallet-qr.png"
    segno.make(text, error="m").save(buf, kind="png", scale=8, border=2)
    buf.seek(0)
    return buf.read()


def _send_balance(chat_id: int):
    """خواندن موجودی از RPC و ارسال پیام (بین /balance و دکمه مشترک)."""
    status = bot.send_message(chat_id, "⏳ در حال دریافت موجودی از شبکه...")
    try:
        bal = wallet.get_balances()
    except wallet.WalletError as exc:
        logger.exception("خطای RPC")
        bot.edit_message_text(
            f"❌ دریافت موجودی ممکن نشد.\n<code>{exc}</code>",
            chat_id,
            status.message_id,
        )
        return
    bot.edit_message_text(
        _balance_text(bal),
        chat_id,
        status.message_id,
        reply_markup=_admin_keyboard(),
        disable_web_page_preview=True,
    )


def _send_charge(chat_id: int):
    text = _charge_text()
    caption = (
        f"💳 شارژ کیف پول هات — شبکه BEP20 (BSC)\n\n"
        f"{config.WALLET_ADDRESS}\n\n"
        f"فقط BEP20! شبکهٔ اشتباه = از دست رفتن دارایی."
    )
    if QR_AVAILABLE:
        try:
            qr_bytes = _qr_png_bytes(config.WALLET_ADDRESS)
            bot.send_photo(chat_id, qr_bytes, caption=caption)
        except Exception:
            logger.exception("ساخت/ارسال QR ناموفق بود؛ ارسال فقط متن")
    bot.send_message(chat_id, text,
                     reply_markup=_admin_keyboard(),
                     disable_web_page_preview=True)


# ── هندلرها ──────────────────────────────────────────────────────


@bot.message_handler(commands=["start"])
def cmd_start(message):
    user = message.from_user
    if _is_admin_id(getattr(user, "id", None)):
        bot.send_message(
            message.chat.id,
            "👋 به <b>ربات کیف پول هات</b> خوش آمدید، ادمین عزیز.\n\n"
            "از دکمه‌های زیر یا دستورهای /balance و /charge استفاده کنید.",
            reply_markup=_admin_keyboard(),
        )
    else:
        bot.send_message(
            message.chat.id,
            "سلام 👋 این یک ربات خصوصی مدیریتی است و عضویت عمومی ندارد.",
        )


@bot.message_handler(commands=["help"])
def cmd_help(message):
    lines = [
        "📖 <b>راهنمای ربات</b>\n",
        "/start — شروع و پنل مدیریت",
        "/help — همین راهنما",
    ]
    if _is_admin_id(getattr(message.from_user, "id", None)):
        lines += [
            "/balance — موجودی USDT (BEP20) و BNB کیف پول هات",
            "/charge — آدرس و QR برای شارژ کیف پول",
        ]
    else:
        lines.append("دسترسی به سایر بخش‌ها فقط برای ادمین است.")
    bot.reply_to(message, "\n".join(lines))


@bot.message_handler(commands=["balance", "inventory", "موجودی"])
@admin_required
def cmd_balance(message):
    _send_balance(message.chat.id)


@bot.message_handler(commands=["charge", "deposit", "شارژ"])
@admin_required
def cmd_charge(message):
    _send_charge(message.chat.id)


@bot.callback_query_handler(func=lambda call: call.data in ("cmd:balance", "cmd:charge"))
def on_admin_button(call):
    if not _is_admin_id(getattr(getattr(call, "from_user", None), "id", None)):
        bot.answer_callback_query(call.id, "⛔️ دسترسی ندارید.", show_alert=True)
        return
    bot.answer_callback_query(call.id)
    if call.data == "cmd:balance":
        _send_balance(call.message.chat.id)
    elif call.data == "cmd:charge":
        _send_charge(call.message.chat.id)


@bot.message_handler(func=lambda _msg: True, content_types=["text"])
def fallback(message):
    if _is_admin_id(getattr(message.from_user, "id", None)):
        bot.reply_to(message, "متوجه نشدم 🤔 از /help استفاده کنید.")
    else:
        bot.reply_to(message, "⛔️ این ربات خصوصی است.")


# ── اجرا با Polling ─────────────────────────────────────────────


def main():
    logger.info("ربات با روش Polling روشن شد | ADMIN_ID=%s", config.ADMIN_ID)
    logger.info("آدرس کیف پول هات: %s (%s)", config.WALLET_ADDRESS, config.WALLET_CHAIN)
    while True:
        try:
            bot.infinity_polling(
                timeout=20,
                long_polling_timeout=10,
                skip_pending=True,
                logger_level=logging.INFO,
            )
        except Exception:
            logger.exception("Polling قطع شد؛ ۵ ثانیه دیگر دوباره تلاش می‌کنم...")
            time.sleep(5)


if __name__ == "__main__":
    main()
