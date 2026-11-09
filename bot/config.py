"""تنظیمات ربات و کیف پول هات.

آدرس *عمومی* کیف پول عمداً داخل خودِ کد قرار داده شده (آدرس عمومی محرمانه نیست).
کلید خصوصی و عبارت بازیابی (mnemonic) فقط در فایل `.env` می‌مانند و
به گیت کامیت نمی‌شوند (فایل .env در .gitignore قرار دارد).
"""

import os
from pathlib import Path

from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / ".env")

# ── ربات تلگرام ──────────────────────────────────────────────────
BOT_TOKEN = os.getenv("BOT_TOKEN", "").strip()

# آیدی عددی ادمین (قابل تغییر با ADMIN_ID در .env؛ پیش‌فرض: آیدی خواسته‌شده)
ADMIN_ID = int(os.getenv("ADMIN_ID", "5222486166"))

# ── کیف پول هات (BNB Smart Chain) ───────────────────────────────
# آدرس عمومی — برای شارژ و نمایش موجودی استفاده می‌شود.
WALLET_ADDRESS = "0x49a724C5d6d77Ac7a3939F3097DabE99F97B5e3C"
WALLET_CHAIN = "BNB Smart Chain"
WALLET_CHAIN_EMOJI = "🟡"

# تتر (USDT) روی BNB Smart Chain — قرارداد Binance-Peg (18 رقم اعشار)
# منبع تأیید: قرارداد رسمی مورد تأیید صرافی‌ها/اکسپلوررها
USDT_BEP20_CONTRACT = "0x55d398326f99059fF775485246999027B3197955"
USDT_DECIMALS = 18
USDT_SYMBOL = "USDT"

BSC_CHAIN_ID = 56
BSCSCAN_ADDRESS_URL = "https://bscscan.com/address/{}"

# آدرس‌های RPC عمومی BSC؛ با BSC_RPC_URL در .env قابل جایگزینی است
# (چند آدرس با کاما جدا کنید؛ در صورت خطا خودکار به بعدی می‌رود)
DEFAULT_BSC_RPCS = (
    "https://bsc-dataseed.binance.org,"
    "https://bsc-dataseed1.defibit.io,"
    "https://bsc-dataseed1.ninicoin.io,"
    "https://bsc-rpc.publicnode.com,"
    "https://rpc.ankr.com/bsc"
)
BSC_RPC_URLS = [
    url.strip()
    for url in os.getenv("BSC_RPC_URL", DEFAULT_BSC_RPCS).split(",")
    if url.strip()
]
RPC_TIMEOUT = float(os.getenv("RPC_TIMEOUT", "10"))
