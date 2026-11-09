#!/usr/bin/env python3
"""
ربات تلگرام کیف پول هات (USDT BEP20) — تک‌فایل و مستقل.

• شبکه: BNB Smart Chain (BEP20)، قرارداد رسمی USDT:
  0x55d398326f99059fF775485246999027B3197955 (۱۸ رقم اعشار)
• آدرس عمومی کیف پول هات — داخل خودِ فایل (عمومی و بی‌خطر):
  0x49a724C5d6d77Ac7a3939F3097DabE99F97B5e3C
• اسرار (توکن ربات و کلید خصوصی/عبارت بازیابی) داخل فایل نیستند؛
  از متغیر محیطی یا فایل .env کنار همین فایل خوانده می‌شوند.

وابستگی‌ها:
    pip install pyTelegramBotAPI requests segno

اجرا:
    python otaku_hot_wallet_bot.py

دستورها (فقط ادمین 5222486166):
    /start    — پنل مدیر
    /help     — راهنما
    /balance  — موجودی USDT (BEP20) و BNB
    /charge   — آدرس و QR برای شارژ کیف پول
"""

import functools
import io
import logging
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import requests
import telebot
from telebot import types

BASE_DIR = Path(__file__).resolve().parent

# ── بارگذاری .env کنار فایل (بدون وابستگی اضافی) ─────────────────
def _load_local_env(path: Path):
    try:
        with open(path, encoding="utf-8") as fh:
            for raw_line in fh:
                line = raw_line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                key, value = key.strip(), value.strip().strip('"').strip("'")
                if key and key not in os.environ:
                    os.environ[key] = value
    except FileNotFoundError:
        pass


_load_local_env(BASE_DIR / ".env")

# ════════════════════════════════════════════════════════════════
# تنظیمات ثابت — آدرس‌های عمومی همین‌جا داخل کد هستند
# ════════════════════════════════════════════════════════════════
WALLET_ADDRESS = "0x49a724C5d6d77Ac7a3939F3097DabE99F97B5e3C"
WALLET_CHAIN = "BNB Smart Chain"
WALLET_CHAIN_EMOJI = "🟡"

USDT_BEP20_CONTRACT = "0x55d398326f99059fF775485246999027B3197955"
USDT_DECIMALS = 18
USDT_SYMBOL = "USDT"

BSC_CHAIN_ID = 56
BSCSCAN_ADDRESS_URL = "https://bscscan.com/address/{}"

# ادمین (قابل تغییر با ADMIN_ID در env/.env)
ADMIN_ID = int(os.getenv("ADMIN_ID", "5222486166"))

# endpointهای عمومی BSC — قابل جایگزینی با BSC_RPC_URL (با کاما چندتایی کنید)
DEFAULT_BSC_RPCS = (
    "https://bsc-dataseed.binance.org,"
    "https://bsc-dataseed1.defibit.io,"
    "https://bsc-dataseed1.ninicoin.io,"
    "https://bsc-rpc.publicnode.com,"
    "https://rpc.ankr.com/bsc"
)
BSC_RPC_URLS = [
    u.strip()
    for u in os.getenv("BSC_RPC_URL", DEFAULT_BSC_RPCS).split(",")
    if u.strip()
]
RPC_TIMEOUT = float(os.getenv("RPC_TIMEOUT", "10"))

# توکن ربات — فقط از env/.env (هرگز داخل این فایل ننویسید)
BOT_TOKEN = os.getenv("BOT_TOKEN", "").strip()

BALANCE_OF_SELECTOR = "70a08231"  # balanceOf(address)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)-7s | %(name)s | %(message)s",
)
logger = logging.getLogger("otaku-bot")

try:
    import segno
    QR_AVAILABLE = True
except ImportError:
    segno = None
    QR_AVAILABLE = False
    logger.warning("پکیج segno نصب نیست؛ QR ارسال نمی‌شود (فقط متن).")

if not BOT_TOKEN:
    sys.exit(
        "❌ BOT_TOKEN تنظیم نشده است. متغیر BOT_TOKEN را در env بگذارید "
        "یا فایل .env را کنار همین اسکریپت بسازید."
    )

bot = telebot.TeleBot(BOT_TOKEN, parse_mode="HTML")


# ════════════════════════════════════════════════════════════════
# بخش کیف پول — خواندن موجودی از BNB Smart Chain (JSON-RPC خام)
# ════════════════════════════════════════════════════════════════
class WalletError(Exception):
    """خطای اتصال به RPC یا پاسخ نامعتبر از شبکه."""


def _encode_balance_of(address: str) -> str:
    addr = address.lower().removeprefix("0x")
    if len(addr) != 40 or any(c not in "0123456789abcdef" for c in addr):
        raise ValueError(f"آدرس BSC نامعتبر است: {address!r}")
    return "0x" + BALANCE_OF_SELECTOR + addr.rjust(64, "0")


def _hex_to_int(value) -> int:
    if not isinstance(value, str) or not value.startswith("0x"):
        raise WalletError(f"پاسخ نامعتبر از RPC (انتظار هگز داشتیم): {value!r}")
    try:
        return int(value, 16)
    except ValueError as exc:
        raise WalletError(f"تبدیل هگز به عدد ممکن نشد: {value!r}") from exc


def _rpc_call(method: str, params: list):
    """فراخوانی JSON-RPC با fallback خودکار بین endpointها."""
    if not BSC_RPC_URLS:
        raise WalletError("هیچ endpoint برای RPC پیکربندی نشده است (BSC_RPC_URL).")

    last_error = None
    payload = {"jsonrpc": "2.0", "id": 1, "method": method, "params": params}
    headers = {"Content-Type": "application/json"}

    for url in BSC_RPC_URLS:
        try:
            resp = requests.post(url, json=payload, timeout=RPC_TIMEOUT,
                                 headers=headers)
            resp.raise_for_status()
            data = resp.json()
        except Exception as exc:  # network / HTTP / JSON errors
            last_error = f"{url} → {exc}"
            continue

        if isinstance(data, dict) and data.get("error"):
            last_error = f"{url} → {data['error']}"
            continue
        if not isinstance(data, dict) or "result" not in data:
            last_error = f"{url} → پاسخ بدون کلید result"
            continue
        return data["result"]

    raise WalletError(last_error or "اتصال به شبکه BSC برقرار نشد.")


def get_bnb_balance(address: str = WALLET_ADDRESS) -> int:
    """موجودی BNB بر حسب wei."""
    return _hex_to_int(_rpc_call("eth_getBalance", [address, "latest"]))


def get_usdt_raw_balance(address: str = WALLET_ADDRESS) -> int:
    """موجودی USDT-BEP20 بر حسب واحد خام (۱۸ اعشار)."""
    calldata = _encode_balance_of(address)
    tx = {"to": USDT_BEP20_CONTRACT, "data": calldata}
    return _hex_to_int(_rpc_call("eth_call", [tx, "latest"]))


def get_balances(address: str = WALLET_ADDRESS) -> dict:
    bnb_wei = get_bnb_balance(address)
    usdt_raw = get_usdt_raw_balance(address)
    return {
        "address": address,
        "bnb": bnb_wei / 10**18,
        "bnb_wei": bnb_wei,
        "usdt": usdt_raw / 10**USDT_DECIMALS,
        "usdt_raw": usdt_raw,
        "updated_at": time.time(),
    }


# ════════════════════════════════════════════════════════════════
# ابزارهای ربات
# ════════════════════════════════════════════════════════════════
def _is_admin_id(user_id) -> bool:
    return user_id is not None and int(user_id) == ADMIN_ID


def admin_required(handler):
    """فقط پیام‌های ادمین را به handler می‌دهد."""

    @functools.wraps(handler)
    def wrapper(message):
        user = message.from_user
        if _is_admin_id(getattr(user, "id", None)):
            return handler(message)
        logger.warning("⛔️ درخواست غیرمجاز از user_id=%s", getattr(user, "id", "?"))
        bot.reply_to(message, "⛔️ شما دسترسی به این ربات را ندارید.")

    return wrapper


def _bscscan_url() -> str:
    return BSCSCAN_ADDRESS_URL.format(WALLET_ADDRESS)


def _admin_keyboard() -> types.InlineKeyboardMarkup:
    kb = types.InlineKeyboardMarkup(row_width=2)
    kb.add(
        types.InlineKeyboardButton("💼 موجودی کیف پول", callback_data="cmd:balance"),
        types.InlineKeyboardButton("💳 شارژ کیف پول", callback_data="cmd:charge"),
    )
    kb.add(types.InlineKeyboardButton("🔍 مشاهده در BscScan", url=_bscscan_url()))
    return kb


def _balance_text(bal: dict) -> str:
    updated = datetime.fromtimestamp(bal["updated_at"], tz=timezone.utc)
    return (
        f"💼 <b>موجودی کیف پول هات</b>\n"
        f"{WALLET_CHAIN_EMOJI} شبکه: <b>{WALLET_CHAIN}</b>\n"
        f"آدرس: <code>{bal['address']}</code>\n\n"
        f"💵 {USDT_SYMBOL} (BEP20): <b>{bal['usdt']:,.2f}</b>\n"
        f"🪙 BNB: <b>{bal['bnb']:.6f}</b>  <i>(برای کارمزد شبکه)</i>\n\n"
        f"🕒 بروزرسانی: {updated.strftime('%Y-%m-%d %H:%M:%S UTC')}"
    )


def _charge_text() -> str:
    return (
        f"💳 <b>شارژ کیف پول هات</b>\n"
        f"{WALLET_CHAIN_EMOJI} شبکه: <b>{WALLET_CHAIN}</b>\n\n"
        f"برای شارژ، دارایی را فقط از بستر <b>BEP20 (بایننس اسمارت چین)</b> "
        f"به این آدرس بفرستید:\n\n"
        f"<code>{WALLET_ADDRESS}</code>\n\n"
        f"💵 قرارداد {USDT_SYMBOL}: <code>{USDT_BEP20_CONTRACT}</code>\n"
        f"🔢 اعشار USDT: <b>{USDT_DECIMALS}</b>\n\n"
        f"⚠️ <b>توجه:</b>\n"
        f"• ارسال روی شبکهٔ غیر از BEP20 (مثلاً ERC20 یا TRC20) = از دست رفتن دارایی.\n"
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
    status = bot.send_message(chat_id, "⏳ در حال دریافت موجودی از شبکه...")
    try:
        bal = get_balances()
    except WalletError as exc:
        logger.exception("خطای RPC")
        bot.edit_message_text(
            f"❌ دریافت موجودی ممکن نشد.\n<code>{exc}</code>",
            chat_id, status.message_id,
        )
        return
    bot.edit_message_text(
        _balance_text(bal), chat_id, status.message_id,
        reply_markup=_admin_keyboard(), disable_web_page_preview=True,
    )


def _send_charge(chat_id: int):
    caption = (
        f"💳 شارژ کیف پول هات — شبکه BEP20 (BSC)\n\n"
        f"{WALLET_ADDRESS}\n\n"
        f"فقط BEP20! شبکهٔ اشتباه = از دست رفتن دارایی."
    )
    if QR_AVAILABLE:
        try:
            bot.send_photo(chat_id, _qr_png_bytes(WALLET_ADDRESS), caption=caption)
        except Exception:
            logger.exception("ساخت/ارسال QR ناموفق بود؛ ارسال فقط متن")
    bot.send_message(chat_id, _charge_text(),
                     reply_markup=_admin_keyboard(),
                     disable_web_page_preview=True)


# ════════════════════════════════════════════════════════════════
# هندلرها
# ════════════════════════════════════════════════════════════════
@bot.message_handler(commands=["start"])
def cmd_start(message):
    if _is_admin_id(getattr(message.from_user, "id", None)):
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


# ════════════════════════════════════════════════════════════════
# اجرا با Polling
# ════════════════════════════════════════════════════════════════
def main():
    logger.info("ربات با روش Polling روشن شد | ADMIN_ID=%s", ADMIN_ID)
    logger.info("آدرس کیف پول هات: %s (%s)", WALLET_ADDRESS, WALLET_CHAIN)
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
