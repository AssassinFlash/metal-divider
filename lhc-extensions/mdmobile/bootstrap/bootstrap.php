<?php

/**
 * Live Helper Chat loads this when the extension name mdmobile is enabled.
 * The class name is erLhcoreClassExtension plus ucfirst(mdmobile).
 */
class erLhcoreClassExtensionMdmobile
{
    public function run()
    {
        require_once __DIR__ . '/../lib/erlhcoreclassmdmobile.php';
    }
}
