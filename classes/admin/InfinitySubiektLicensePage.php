<?php
/**
 * Zakładka „Licencja” strony konfiguracji, wariant demo: bez klucza i statusu licencji. Pokazuje, co daje
 * wersja pełna, kontakt i blok „Więcej modułów”. Do tego komunikat demo nad zakładkami (renderDemoNotice()).
 * Teksty według wspólnej specyfikacji licencji modułów InfinityWeb.pl.
 *
 * HTML składamy w PHP: tłumaczenie przechodzi przez escape bez podwójnego kodowania (PrestaShop
 * w części wersji już je escapuje).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class InfinitySubiektLicensePage
{
    const TRANSLATION_SOURCE = 'infinitysubiektlicensepage';

    /** @var Module */
    private $module;

    /** @var InfinitySubiektView */
    private $view;

    /** @var Context */
    private $context;

    public function __construct(Module $module, InfinitySubiektView $view, Context $context)
    {
        $this->module = $module;
        $this->view = $view;
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function render()
    {
        $features = [];
        foreach ($this->features() as $feature) {
            $features[] = $this->html($feature);
        }

        return $this->view->render('admin/tab_license.tpl', [
            'features' => $features,
            'github_url' => InfinitySubiektDemo::GITHUB_URL,
            'site_url' => InfinitySubiektDemo::SITE_URL,
            'html' => [
                'header' => $this->html(
                    $this->l('%name% · wersja %version% · Wersja demo · autor InfinityWeb.pl'),
                    ['%name%' => (string) $this->module->displayName, '%version%' => (string) $this->module->version]
                ),
                'demo' => '<strong>' . $this->html($this->l('To jest wersja demo.')) . '</strong> '
                    . $this->html($this->l('Działa w niej część funkcji modułu. Pozostałe widać w panelu i w kodzie, ale działają tylko w wersji pełnej.')),
                'full' => $this->html($this->l('Wersja pełna:')),
                'contact' => $this->html(
                    $this->l('Chcesz wersję pełną? Napisz na %email% albo zajrzyj na %url%'),
                    [],
                    ['%email%' => $this->mailto(), '%url%' => $this->externalLink(InfinitySubiektDemo::FULL_VERSION_URL)]
                ),
                'more_title' => $this->html($this->l('Więcej modułów PrestaShop od InfinityWeb.pl')),
                'more_github' => $this->html(
                    $this->l('Dema innych modułów znajdziesz na GitHubie: %url%'),
                    [],
                    ['%url%' => $this->externalLink(InfinitySubiektDemo::GITHUB_URL)]
                ),
                'more_site' => $this->html(
                    $this->l('Opisy, przykłady użycia i kontakt: %url%'),
                    [],
                    ['%url%' => $this->externalLink(InfinitySubiektDemo::SITE_URL)]
                ),
                'more_github_button' => $this->html($this->l('Moduły na GitHubie')),
                'footer' => $this->html($this->l('Wersja demo nie sprawdza licencji i nie wysyła żadnych danych do autora.')),
            ],
        ]);
    }

    /**
     * Komunikat demo nad zakładkami (poza zakładką „Licencja”), w miejscu paska licencji wersji pełnej.
     *
     * @return string
     */
    public function renderDemoNotice()
    {
        return '<div class="alert alert-info infsubiekt-demo-notice">'
            . '<strong>' . $this->html($this->l('Wersja demo.')) . '</strong> '
            . $this->html($this->l('Konfiguracja, kolejka zadań, panele w zamówieniu, kliencie i produkcie oraz cron działają jak w wersji pełnej. Agent demo łączy się ze sklepem, odpowiada na „Ping”, pobiera formy płatności i cechy towarów i aktualizuje się przyciskiem w zakładce „Agent”. Pozostałe zadania (kontrahenci, towary, stany, ceny zakupu, dokumenty i PDF) kończą się komunikatem o wersji pełnej i niczego nie zmieniają w Subiekcie.'))
            . '<br>'
            . $this->html(
                $this->l('Wersja pełna: napisz na %email% albo zajrzyj na %url%. Po jej wgraniu na demo ustawienia zostają, a agenta zaktualizujesz jednym przyciskiem.'),
                [],
                ['%email%' => $this->mailto(), '%url%' => $this->externalLink(InfinitySubiektDemo::FULL_VERSION_URL)]
            )
            . ' <a href="' . $this->escape(InfinitySubiektSettingsPage::tabUrl($this->module, InfinitySubiektSettingsPage::TAB_LICENSE)) . '">'
            . $this->html($this->l('Licencja')) . ' &rarr;</a></div>';
    }

    /**
     * Funkcje wersji pełnej, te same co w README i LICENSE.md dema.
     *
     * @return string[]
     */
    private function features()
    {
        return [
            $this->l('kartoteki kontrahentów i towarów wysyłane do Subiekta GT z karty klienta, zamówienia i produktu'),
            $this->l('faktury, paragony i inne dokumenty sprzedaży z zamówienia, z ochroną przed duplikatem, i ich PDF w panelu zamówienia'),
            $this->l('stany magazynowe z Subiekta do sklepu (cron) i ręcznie ze sklepu do Subiekta (dokument PW albo RW)'),
            $this->l('ceny zakupu z Subiekta do ceny hurtowej produktu (cron raz na dobę)'),
            $this->l('dopasowanie dokumentów wystawionych w Subiekcie przed instalacją modułu'),
            $this->l('czat AI (moduł infinitymcp): odczyt dokumentów Subiekta, stan kolejki, pobieranie stanów, cen i PDF przez paczki zmian akceptowane w BO'),
        ];
    }

    /**
     * @return string gotowy HTML linku mailto
     */
    private function mailto()
    {
        $email = InfinitySubiektDemo::CONTACT_EMAIL;

        return '<a href="mailto:' . $this->escape($email) . '"><strong>' . $this->escape($email) . '</strong></a>';
    }

    /**
     * Tłumaczenie jako HTML: tekst escapowany bez podwójnego kodowania, znaczniki z $values escapowane,
     * znaczniki z $links wstawiane jako gotowy HTML.
     *
     * @param string $text
     * @param array $values znacznik => zwykły tekst
     * @param array $links znacznik => gotowy HTML
     *
     * @return string
     */
    private function html($text, array $values = [], array $links = [])
    {
        $replace = $links;
        foreach ($values as $key => $value) {
            $replace[$key] = $this->escape((string) $value);
        }

        return strtr(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false), $replace);
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function externalLink($url)
    {
        return '<a href="' . $this->escape($url) . '" target="_blank" rel="noopener">' . $this->escape($url) . '</a>';
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private function escape($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function l($string)
    {
        return $this->module->l($string, self::TRANSLATION_SOURCE);
    }
}
