# ربات تلگرام کیف پول هات (USDT BEP20)

ربات مدیریتی تلگرام با **pyTelegramBotAPI** و روش **Polling**؛ مخصوص ادمین (`5222486166`) تا موجودی کیف پول هات را ببیند و آن را شارژ کند.

- شبکه: **BNB Smart Chain (BEP20)** — تتر (USDT)
- قرارداد رسمی USDT روی BSC: `0x55d398326f99059fF775485246999027B3197955` (۱۸ اعشار)
- آدرس کیف پول هات (عمومی، در `config.py`): `0x49a724C5d6d77Ac7a3939F3097DabE99F97B5e3C`

## دستورات

| دستور | توضیح | دسترسی |
|---|---|---|
| `/start` | شروع + پنل دکمه‌های مدیریت | همه (پنل فقط برای ادمین) |
| `/help` | راهنما | همه |
| `/balance` | موجودی USDT (BEP20) و BNB کیف پول روی زنجیره | فقط ادمین |
| `/charge` | آدرس شارژ + QR کد | فقط ادمین |

## راه‌اندازی

```bash
cd bot
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

cp .env.example .env   # سپس BOT_TOKEN و کلیدها را داخلش بگذارید
python bot.py
```

> توکن را از **@BotFather** بگیرید (`/newbot`). این مخزن از قبل یک `.env` محلی دارد؛
> اگر روی دستگاه دیگری استقرار می‌دهید آن را دستی کپی کنید — **هرگز کامیت نکنید.**

## تست سریع

```bash
cd bot
python -c "import config, wallet; print(config.WALLET_ADDRESS)"
```

## نکات امنیتی مهم ⚠️

1. **عبارت بازیابی (mnemonic)** یعنی دسترسی کامل به دارایی‌های کیف پول. آن را در جای امن (آفلاین) یادداشت کنید.
2. فایل `.env` در `.gitignore` است و **نباید** به گیت برود. اگر اشتباهی کامیت شد، بلافاصله کیف پول را عوض کنید.
3. توکنی که در چت فرستاده شد را به کسی ندهید؛ اگر لو رفت از BotFather با `/revoke` باطلش کنید.
4. ارسال دارایی فقط روی شبکهٔ **BEP20 (BSC)**؛ ارسال روی شبکهٔ اشتباه = از دست رفتن دارایی.
5. برای انتقال/برداشت از کیف پول به مقداری **BNB** برای کارمزد نیاز دارید.

## ساخت کیف پول جدید (اختیاری)

```bash
pip install -r requirements-dev.txt
python scripts/generate_wallet.py              # فقط چاپ می‌کند
python scripts/generate_wallet.py --write-env  # .env را با کیف جدید می‌سازد
# سپس آدرس جدید را در config.py → WALLET_ADDRESS جایگزین کنید
```

## ساختار

```
bot/
├── bot.py                     # ربات (polling) و هندلرهای دستورات
├── wallet.py                  # خواندن موجودی از RPC بایننس (JSON-RPC خام)
├── config.py                  # آدرس کیف پول (عمومی) + خواندن .env
├── requirements.txt           # وابستگی‌های اجرا
├── requirements-dev.txt       # + ابزار ساخت کیف پول
├── scripts/generate_wallet.py # ساخت کیف پول تازه
└── .env.example               # الگوی تنظیمات
```
