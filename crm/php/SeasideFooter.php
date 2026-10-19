<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Shared "Powered by EspoCRM · Source code (AGPLv3)" footer for the client-facing pages.
// EspoCRM is AGPLv3 and its §7(b) term requires user-facing screens of a modified version to show a
// legal notice that keeps the word "EspoCRM". One helper, so every page shows the same thing.
// Installed to custom/Espo/Custom/Seaside/Footer.php by crm/install.py.
namespace Espo\Custom\Seaside;

class Footer
{
    public static function html(): string
    {
        return '<p style="margin:1rem 0 0;padding-top:.6rem;border-top:1px solid #D3E6E0;'
            . 'text-align:center;color:#8a7f76;font-size:.8rem">'
            . 'Powered by <a style="color:#2F6F86" href="https://www.espocrm.com">EspoCRM</a>'
            . ' · <a style="color:#2F6F86" href="https://github.com/Cameron356/seasidebaby-espocrm">Source code (AGPLv3)</a>'
            . '</p>';
    }
}
