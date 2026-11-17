<?php
/**
 * Render szablonów Smarty modułu z izolowanymi zmiennymi.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektView
{
    /** @var Module */
    private $module;

    /** @var Context */
    private $context;

    public function __construct(Module $module, Context $context)
    {
        $this->module = $module;
        $this->context = $context;
    }

    /**
     * @param string $template ścieżka względem views/templates/, np. 'admin/settings.tpl'
     * @param array $vars
     *
     * @return string
     */
    public function render($template, array $vars = [])
    {
        $tpl = $this->context->smarty->createTemplate(
            $this->module->getLocalPath() . 'views/templates/' . $template,
            $this->context->smarty
        );
        $tpl->assign($vars);

        return $tpl->fetch();
    }
}
