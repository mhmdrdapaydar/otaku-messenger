# ربات تلگرام کیف پول هات (USDT BEP20) — تک‌فایل

ربات مدیریتی تلگرام با **pyTelegramBotAPI** و **Polling**، مخصوص ادمین (`5222486166`)؛
برای اجرا فقط به **یک فایل** نیاز دارید: [`otaku_hot_wallet_bot.py`](otaku_hot_wallet_bot.py)

- شبکه: **BNB Smart Chain (BEP20)** — تتر (USDT)
- قرارداد رسمی USDT روی BSC: `0x55d398326f99059fF775485246999027B3197955` (۱۸ اعشار)
- آدرس کیف پول هات (عمومی، داخل خودِ فایل): `0x49a724C5d6d77Ac7a3939F3097DabE99F97B5e3C`

> 🔐 آدرس عمومی داخل فایل است، ولی **توکن ربات و کلید خصوصی/عبارت بازیابی داخل فایل نیستند**.
> آن‌ها از env یا فایل `.env` کنار اسکریپت خوانده می‌شوند.

## دستورها

| دستور | توضیح | دسترسی |
|---|---|---|
| `/start` | شروع + پنل دکمه‌های مدیریت | همه (پنل فقط برای ادمین) |
| `/help` | راهنما | همه |
| `/balance` | موجودی USDT (BEP20) و BNB روی زنجیره | فقط ادمین |
| `/charge` | آدرس شارژ + QR کد | فقط ادمین |

## راه‌اندازی (اپ جدا)

```bash
pip install pyTelegramBotAPI requests segno

# یک فایل .env کنار otaku_hot_wallet_bot.py بسازید:
cat > .env <<'EOF'
BOT_TOKEN=توکن_BotFather
WALLET_PRIVATE_KEY=0x...
WALLET_MNEMONIC=twelve word phrase...
EOF

python otaku_hot_wallet_bot.py
```

یا بدون `.env`:

```bash
export BOT_TOKEN=توکن_BotFather
python otaku_hot_wallet_bot.py
```

## نکات امنیتی ⚠️

1. **عبارت بازیابی (mnemonic)** = دسترسی کامل به دارایی‌ها؛ آن را آفلاین یادداشت کنید.
2. `.env` در `.gitignore` است — **هرگز کامیت نکنید**. اگر لو رفت، کیف پول را عوض کنید.
3. توکنی که در چت فرستاده شد را به کسی ندهید؛ در صورت لو رفتن با `/revoke` در BotFather باطلش کنید.
4. ارسال فقط روی شبکهٔ **BEP20 (BSC)**؛ شبکهٔ اشتباه = از دست رفتن دارایی.
5. برای برداشت/انتقال بعدی به مقدار کمی **BNB** برای کارمزد نیاز است.

## ساخت کیف پول جدید (اختیاری)

```bash
pip install -r requirements-dev.txt
python scripts/generate_wallet.py              # فقط چاپ می‌کند
python scripts/generate_wallet.py --write-env  # .env را با کیف جدید می‌سازد
# سپس WALLET_ADDRESS را داخل otaku_hot_wallet_bot.py جایگزین کنید
```
