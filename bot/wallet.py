"""خواندن موجودی کیف پول از شبکه BNB Smart Chain (JSON-RPC خام، بدون web3).

دو تابع اصلی:
  - get_bnb_balance()     → موجودی BNB (به wei)
  - get_usdt_raw_balance() → موجودی USDT-BEP20 (به واحد خام، ۱۸ اعشار)
  - get_balances()         → هر دو به‌همراه زمان بروزرسانی

منطق شبکه‌ای در یک نقطه (_rpc_call) متمرکز شده تا تست آفلاین ساده باشد.
"""

import time

import requests

import config

BALANCE_OF_SELECTOR = "70a08231"  # balanceOf(address)


class WalletError(Exception):
    """خطای اتصال به RPC یا پاسخ نامعتبر از شبکه."""


def _encode_balance_of(address: str) -> str:
    """ساخت calldata تابع balanceOf برای eth_call."""
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
    if not config.BSC_RPC_URLS:
        raise WalletError("هیچ endpoint برای RPC پیکربندی نشده است (BSC_RPC_URL).")

    last_error = None
    payload = {"jsonrpc": "2.0", "id": 1, "method": method, "params": params}
    headers = {"Content-Type": "application/json"}

    for url in config.BSC_RPC_URLS:
        try:
            resp = requests.post(url, json=payload, timeout=config.RPC_TIMEOUT,
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


def get_bnb_balance(address: str = config.WALLET_ADDRESS) -> int:
    """موجودی BNB بر حسب wei (int)."""
    result = _rpc_call("eth_getBalance", [address, "latest"])
    return _hex_to_int(result)


def get_usdt_raw_balance(address: str = config.WALLET_ADDRESS) -> int:
    """موجودی USDT-BEP20 بر حسب واحد خام (۱۸ اعشار)."""
    calldata = _encode_balance_of(address)
    tx = {"to": config.USDT_BEP20_CONTRACT, "data": calldata}
    result = _rpc_call("eth_call", [tx, "latest"])
    return _hex_to_int(result)


def get_balances(address: str = config.WALLET_ADDRESS) -> dict:
    bnb_wei = get_bnb_balance(address)
    usdt_raw = get_usdt_raw_balance(address)
    return {
        "address": address,
        "bnb": bnb_wei / 10**18,
        "bnb_wei": bnb_wei,
        "usdt": usdt_raw / 10 ** config.USDT_DECIMALS,
        "usdt_raw": usdt_raw,
        "updated_at": time.time(),
    }
