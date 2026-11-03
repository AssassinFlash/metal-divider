<?php

/**
 * Live Helper Chat loads this when the extension name mdvisitor is enabled.
 * The class name is erLhcoreClassExtension plus ucfirst(mdvisitor).
 */
class erLhcoreClassExtensionMdvisitor
{
    public function run()
    {
        // Templates and assets are resolved by erLhcoreClassDesign from
        // extension/mdvisitor/design/mdvisitortheme/.
        // Hostinger shared MySQL defaults wait_timeout to 20s; raise the
        // session timeout so LHC's open transaction survives synchronous AI
        // webhook turns.
        try {
            $db = ezcDbInstance::get();
            $db->query('SET SESSION wait_timeout = 120, SESSION interactive_timeout = 120');
        } catch (Throwable $e) {
            // Ignore when database is not yet initialized.
        }
    }
}
