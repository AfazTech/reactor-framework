<?php

namespace Reactor\Core;

use Reactor\Contracts\LanguageInterface;

/**
 * Language/translation manager that loads JSON language files.
 */
class Language implements LanguageInterface
{
    private array $translations = [];
    private string $defaultLanguage;
    private array $loadedLanguages = [];
    private string $langDir;

    /**
     * Constructor.
     *
     * @param Paths  $paths  Paths helper.
     * @param Config $config Configuration manager.
     */
    public function __construct(Paths $paths, Config $config)
    {
        $this->langDir = $paths->lang();
        $this->defaultLanguage = $config->get('app.default_language', 'en');
        $this->loadAllLanguages();
    }

    /**
     * Load all JSON language files from the language directory.
     */
    private function loadAllLanguages(): void
    {
        if (!is_dir($this->langDir)) {
            mkdir($this->langDir, 0755, true);
            return;
        }

        $files = glob($this->langDir . '/*.json');
        foreach ($files as $file) {
            $langCode = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($file);
            $this->translations[$langCode] = json_decode($content, true) ?? [];
            $this->loadedLanguages[] = $langCode;
        }

        if (!isset($this->translations[$this->defaultLanguage])) {
            $this->translations[$this->defaultLanguage] = [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, ?string $lang = null, array $params = []): string
    {
        $lang = $lang ?? $this->defaultLanguage;

        $text = $this->translations[$lang][$key] ??
                $this->translations[$this->defaultLanguage][$key] ??
                $key;

        foreach ($params as $param => $value) {
            $text = str_replace('{' . $param . '}', $value, $text);
        }

        return $text;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableLanguages(): array
    {
        return $this->loadedLanguages;
    }

    /**
     * {@inheritdoc}
     */
    public function getLanguageName(string $code): string
    {
        $names = [
            'fa' => 'فارسی',
            'en' => 'English',
        ];

        return $names[$code] ?? $code;
    }

    /**
     * {@inheritdoc}
     */
    public function reload(): void
    {
        $this->translations = [];
        $this->loadedLanguages = [];
        $this->loadAllLanguages();
    }

    /**
     * {@inheritdoc}
     */
    public function addTranslation(string $lang, string $key, string $value): void
    {
        if (!isset($this->translations[$lang])) {
            $this->translations[$lang] = [];
        }
        $this->translations[$lang][$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function saveLanguageFile(string $lang): bool
    {
        if (!isset($this->translations[$lang])) {
            return false;
        }

        if (!is_dir($this->langDir)) {
            mkdir($this->langDir, 0755, true);
        }

        $file = $this->langDir . '/' . $lang . '.json';
        $json = json_encode($this->translations[$lang], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return file_put_contents($file, $json) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultLanguage(): string
    {
        return $this->defaultLanguage;
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultLanguage(string $lang): void
    {
        if (in_array($lang, $this->loadedLanguages, true)) {
            $this->defaultLanguage = $lang;
        }
    }
}
