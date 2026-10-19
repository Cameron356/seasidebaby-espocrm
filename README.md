# Seaside Baby Nurturer — EspoCRM customizations

The code that makes the Seaside Baby Nurturer office book (EspoCRM) work, **as deployed**: the family page,
the booking form, the "more hours" request, the tonight board, the postpartum plan and shift hooks, the
theme, and the data model + installer that put it all into EspoCRM. Published under **AGPL-3.0-or-later** to
satisfy the AGPL (EspoCRM is AGPLv3 and these pages modify it), so anyone who uses the modified book over the
network can get its source.

This is a **compliance snapshot**, refreshed **at least quarterly** (and after any client-facing deploy) from a
private working repo. Production hostnames and the internal IP are replaced with `example.com` /
`127.0.0.1` placeholders.

## What runs on

- **EspoCRM 10.0.9** (`espocrm/espocrm:10.0.9`), upstream: <https://github.com/espocrm/espocrm>

## Layout

- `crm/model.py` — the data model (every record type and field); `crm/install.py` writes it into EspoCRM.
- `crm/php/` — the entry points (`?entryPoint=familyPage`, `seasideRequest`, `seasideMoreHours`,
  `tonightBoard`) and the postpartum plan / shift hooks.
- `crm/theme/` — the Seaside theme CSS + logo; `crm/client/` — the plan/shift JS handler.

## License

AGPL-3.0-or-later. See [`LICENSE`](LICENSE).
