from __future__ import annotations

import os
from dataclasses import dataclass

from dotenv import load_dotenv


def _as_bool(value: str | None, default: bool = True) -> bool:
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


@dataclass(frozen=True, slots=True)
class Settings:
    ilias_mode: str = "demo"
    ilias_url: str = ""
    ilias_client_id: str = ""
    ilias_username: str = ""
    ilias_password: str = ""
    ilias_verify_tls: bool = True
    moodle_url: str = ""
    moodle_token: str = ""
    moodle_verify_tls: bool = True
    log_level: str = "INFO"

    @classmethod
    def from_env(cls) -> Settings:
        load_dotenv()
        return cls(
            ilias_mode=os.getenv("ILIAS_MODE", "demo").strip().lower(),
            ilias_url=os.getenv("ILIAS_URL", "").rstrip("/"),
            ilias_client_id=os.getenv("ILIAS_CLIENT_ID", ""),
            ilias_username=os.getenv("ILIAS_USERNAME", ""),
            ilias_password=os.getenv("ILIAS_PASSWORD", ""),
            ilias_verify_tls=_as_bool(os.getenv("ILIAS_VERIFY_TLS")),
            moodle_url=os.getenv("MOODLE_URL", "").rstrip("/"),
            moodle_token=os.getenv("MOODLE_TOKEN", ""),
            moodle_verify_tls=_as_bool(os.getenv("MOODLE_VERIFY_TLS")),
            log_level=os.getenv("LOG_LEVEL", "INFO").upper(),
        )
