#!/usr/bin/env python3
"""Parse a saved AQUASOFT/Vagner-Ural catalog page and export it as a
WooCommerce product-import CSV (same column layout as a "WooCommerce
Product CSV Exporter" export).

The site itself cannot be fetched automatically from this environment
(outbound network access to it is blocked), so the workflow is:

  1. Save the catalog page's HTML (e.g. browser "Save As" or view-source)
     to a local file.
  2. Run this script against that file to produce an importable CSV.

Usage:
    python3 src/scrape_catalog.py samples/habarovsk-katalog.html \
        --base-url https://habarovsk.vagner-ural.ru \
        --out output/habarovsk-katalog.csv
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import re
from pathlib import Path
from urllib.parse import urljoin

from bs4 import BeautifulSoup

CSV_HEADER = [
    "ID", "Тип", "Артикул", "GTIN, UPC, EAN или ISBN", "Имя", "Опубликован",
    "Рекомендуемый?", "Видимость в каталоге", "Краткое описание", "Описание",
    "Дата начала действия скидки", "Дата окончания действия скидки",
    "Статус налога", "Налоговый класс", "Наличие", "Запасы",
    "Величина малых запасов", "Возможен ли предзаказ?",
    "Продано индивидуально?", "Вес (кг)", "Длина (см)", "Ширина (см)",
    "Высота (см)", "Разрешить отзывы от клиентов?", "Примечание к покупке",
    "Акционная цена", "Базовая цена", "Категории", "Метки",
    "Класс доставки", "Изображения", "Лимит скачивания",
    "Дней срока скачивания", "Родительский", "Сгруппированные товары",
    "Апсэлы", "Кросселы", "Внешний URL", "Текст кнопки", "Позиция",
    "Бренды", "Мета: _eael_post_view_count", "Название атрибута 1",
    "Значения атрибутов 1", "Видимость атрибута 1", "Глобальный атрибут 1",
    "Мета: _uwp_source_url", "Мета: _uwp_content_hash",
]

PRICE_RE = re.compile(r"[\d\s]+")


def _clean_price(text: str | None) -> str:
    if not text:
        return ""
    digits = PRICE_RE.search(text)
    if not digits:
        return ""
    return digits.group(0).replace(" ", "").replace("\xa0", "").strip()


def parse_catalog(html: str, base_url: str) -> list[dict]:
    soup = BeautifulSoup(html, "html.parser")
    products = []

    for card in soup.select(".p-card"):
        name_el = card.select_one(".p-name")
        if not name_el:
            continue
        name = name_el.get_text(strip=True)

        link_el = card.select_one("a.p-img") or card.select_one("a.p-name")
        product_url = urljoin(base_url, link_el["href"]) if link_el and link_el.get("href") else ""

        img_el = card.select_one(".p-img img")
        image_url = urljoin(base_url, img_el["src"]) if img_el and img_el.get("src") else ""

        sale_price = _clean_price(card.select_one(".p-price").get_text() if card.select_one(".p-price") else None)
        regular_price = _clean_price(card.select_one(".p-price-old").get_text() if card.select_one(".p-price-old") else None)

        status_el = card.select_one(".p-status")
        in_stock = bool(status_el and "наличии" in status_el.get_text(strip=True).lower())

        features = [f.get_text(strip=True) for f in card.select(".p-feature") if f.get_text(strip=True)]

        products.append({
            "name": name,
            "url": product_url,
            "image": image_url,
            "sale_price": sale_price,
            "regular_price": regular_price,
            "in_stock": in_stock,
            "features": features,
        })

    return products


def build_short_description(features: list[str]) -> str:
    if not features:
        return ""
    items = "".join(f"<li>{f}</li>" for f in features)
    return f"<ul>{items}</ul>"


def content_hash(product: dict) -> str:
    payload = "|".join([
        product["name"], product["regular_price"], product["sale_price"],
        ";".join(product["features"]),
    ])
    return hashlib.md5(payload.encode("utf-8")).hexdigest()


def to_row(product: dict) -> list[str]:
    row = {h: "" for h in CSV_HEADER}
    row["Тип"] = "simple"
    row["Имя"] = product["name"]
    row["Опубликован"] = "1"
    row["Рекомендуемый?"] = "0"
    row["Видимость в каталоге"] = "visible"
    row["Краткое описание"] = build_short_description(product["features"])
    row["Статус налога"] = "taxable"
    row["Наличие"] = "1" if product["in_stock"] else "0"
    row["Возможен ли предзаказ?"] = "0"
    row["Продано индивидуально?"] = "0"
    row["Разрешить отзывы от клиентов?"] = "1"
    if product["sale_price"] and product["sale_price"] != product["regular_price"]:
        row["Акционная цена"] = product["sale_price"]
        row["Базовая цена"] = product["regular_price"] or product["sale_price"]
    else:
        row["Базовая цена"] = product["regular_price"] or product["sale_price"]
    row["Изображения"] = product["image"]
    row["Позиция"] = "0"
    row["Мета: _uwp_source_url"] = product["url"]
    row["Мета: _uwp_content_hash"] = content_hash(product)
    return [row[h] for h in CSV_HEADER]


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("html_file", type=Path, help="Path to saved catalog HTML")
    parser.add_argument("--base-url", required=True, help="Site base URL to resolve relative links/images")
    parser.add_argument("--out", type=Path, required=True, help="Output CSV path")
    args = parser.parse_args()

    html = args.html_file.read_text(encoding="utf-8")
    products = parse_catalog(html, args.base_url)

    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", encoding="utf-8-sig", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(CSV_HEADER)
        for product in products:
            writer.writerow(to_row(product))

    print(f"Parsed {len(products)} products -> {args.out}")


if __name__ == "__main__":
    main()
