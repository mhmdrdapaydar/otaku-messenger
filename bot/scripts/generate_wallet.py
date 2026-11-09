#!/usr/bin/env python3
"""ساخت یک کیف پول هات تازه (BIP-39 + مسیر BIP-44 m/44'/60'/0'/0/0).

استفاده:
    pip install -r requirements-dev.txt
    python scripts/generate_wallet.py            # فقط چاپ می‌کند، چیزی ذخیره نمی‌شود
    python scripts/generate_wallet.py --write-env  # فایل .env را با کیف جدید می‌نویسد

توجه: با --write-env اگر .env از قبل وجود داشته باشد، بدون --force دست نمی‌خورد.
بعد از ساخت، آدرس جدید را حتماً داخل bot/config.py (WALLET_ADDRESS) جایگزین کنید.
"""

import argparse
import os
import sys

from eth_account import Account
from mnemonic import Mnemonic

DERIVATION_PATH = "m/44'/60'/0'/0/0"
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ENV_PATH = os.path.join(BASE_DIR, ".env")


def main() -> int:
    parser = argparse.ArgumentParser(description="ساخت کیف پول هات جدید برای BSC")
    parser.add_argument("--write-env", action="store_true",
                        help="فایل .env را با کلیدهای تازه بنویس/به‌روز کن")
    parser.add_argument("--force", action="store_true",
                        help="در صورت وجود .env، آن را بازنویسی کن")
    args = parser.parse_args()

    Account.enable_unaudited_hdwallet_features()
    phrase = Mnemonic("english").generate(strength=128)
    acct = Account.from_mnemonic(phrase, account_path=DERIVATION_PATH)
    address = acct.address
    private_key = "0x" + acct.key.hex().removeprefix("0x")

    print("＝" * 56)
    print("کیف پول هات ساخته شد — این اطلاعات را امن نگه دارید!")
    print("＝" * 56)
    print(f"مسیر مشتق‌سازی : {DERIVATION_PATH}")
    print(f"آدرس عمومی     : {address}")
    print(f"عبارت بازیابی  : {phrase}")
    print(f"کلید خصوصی     : {private_key}")
    print("＝" * 56)
    print("⚠️ آدرس را داخل bot/config.py → WALLET_ADDRESS جایگزین کنید.")
    print("⚠️ کلید خصوصی/عبارت بازیابی را هرگز در گیت یا چت عمومی نگذارید.")

    if args.write_env:
        if os.path.exists(ENV_PATH) and not args.force:
            print(f"\n❌ {ENV_PATH} از قبل وجود دارد؛ برای بازنویسی --force بدهید.")
            return 1
        bot_token = os.getenv("BOT_TOKEN", "YOUR_BOT_TOKEN_FROM_BOTFATHER")
        with open(ENV_PATH, "w", encoding="utf-8") as fh:
            fh.write(
                "BOT_TOKEN={token}\n"
                "ADMIN_ID=5222486166\n"
                "WALLET_MNEMONIC={mnemonic}\n"
                "WALLET_PRIVATE_KEY={key}\n".format(
                    token=bot_token, mnemonic=phrase, key=private_key,
                )
            )
        os.chmod(ENV_PATH, 0o600)
        print(f"\n✅ فایل .env نوشته شد ({ENV_PATH}) با دسترسی 600.")
        print("⚠️ BOT_TOKEN را داخل آن حتماً بررسی کنید.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
