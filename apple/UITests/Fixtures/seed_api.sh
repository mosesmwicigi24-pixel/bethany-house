#!/usr/bin/env bash
# UI-tour fixture, second half: records that are made through the Hub's own
# API, as a person would make them (a production job raised with its material
# choices, which also starts a draft purchase order; a USD quotation).
# TEST DATA ONLY: run against the throwaway Hub of the UI-tour CI job.
#   seed_api.sh <hub url> <token>
set -euo pipefail
HUB="${1:?hub url}"; TOKEN="${2:?token}"
call() { curl -fsS -X "$1" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' "$HUB/api/v1/admin$2" ${3:+-d "$3"}; echo; }

# Order 2, line 2: two test shirts. Fabric 1 m from store + 4 m to buy; buttons from store; lining to buy.
call POST /orders/2/items/2/production '{"color":"White","materials":[{"material_id":1,"from_store":1,"to_buy":4},{"material_id":2,"from_store":16,"to_buy":0},{"material_id":3,"from_store":0,"to_buy":3}]}'
call POST /quotations '{"currency_code":"USD","customer_first_name":"Test","customer_last_name":"Parish","customer_email":"parish@bethanyhouse.test","items":[{"product_id":1,"product_name":"Test Clergy Cope","quantity":2,"unit_price":171.5},{"product_id":2,"product_name":"Test Communion Cups (box)","quantity":400,"unit_price":0.1}]}'
