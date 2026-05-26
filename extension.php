<?php

/**
 * ArticleTranslatorExtension - FreshRSS extension for paragraph-by-paragraph AI translation.
 */
final class ArticleTranslatorExtension extends Minz_Extension
{
  /**
   * AI requests are proxied by the FreshRSS server, so the browser does not
   * need extra external CSP permissions for model providers.
   */
  protected array $csp_policies = [];

  #[\Override]
  public function init(): void
  {
    $this->registerHook('entry_before_display', [$this, 'addTranslateButton']);
    $this->registerController('ArticleTranslator');
    $this->registerTranslates(__DIR__ . '/i18n');

    if (is_null(FreshRSS_Context::$user_conf->article_translator_prompt)) {
      FreshRSS_Context::$user_conf->article_translator_prompt = _t('ArticleTranslator.config.default_prompt');
      FreshRSS_Context::$user_conf->save();
    }

    if (is_null(FreshRSS_Context::$user_conf->article_translator_provider)) {
      FreshRSS_Context::$user_conf->article_translator_provider = 'openai';
      FreshRSS_Context::$user_conf->save();
    }

    Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
    Minz_View::appendScript($this->getFileUrl('axios.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('marked.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
  }

  /**
   * Add the translate control before each article body.
   */
  public function addTranslateButton(FreshRSS_Entry $entry): FreshRSS_Entry
  {
    if (Minz_Request::param('a') === 'rss') {
      return $entry;
    }

    $translateUrl = Minz_Url::display([
      'c' => 'ArticleTranslator',
      'a' => 'translate',
      'params' => [
        'id' => $entry->id(),
      ],
    ]);

    $buttonText = _t('ArticleTranslator.button.translate');
    $loadingText = _t('ArticleTranslator.status.loading');
    $doneText = _t('ArticleTranslator.status.done');
    $errorText = _t('ArticleTranslator.status.error');
    $requestFailedText = _t('ArticleTranslator.status.request_failed');
    $noContentText = _t('ArticleTranslator.status.no_content');
    $resultLabel = _t('ArticleTranslator.label.result');

    $entry->_content(
      '<div class="oai-translation-wrap" data-entry-id="' . $this->escape($entry->id()) . '" '
      . 'data-entry-title="' . $this->escape($entry->title()) . '">'
      . '<button type="button" data-request="' . $translateUrl . '" '
      . 'data-translate-text="' . $this->escape($buttonText) . '" '
      . 'data-loading-text="' . $this->escape($loadingText) . '" '
      . 'data-done-text="' . $this->escape($doneText) . '" '
      . 'data-error-text="' . $this->escape($errorText) . '" '
      . 'data-request-failed-text="' . $this->escape($requestFailedText) . '" '
      . 'data-no-content-text="' . $this->escape($noContentText) . '" '
      . 'data-result-label="' . $this->escape($resultLabel) . '" '
      . 'class="oai-translation-btn">' . $this->escape($buttonText) . '</button>'
      . '<div class="oai-translation-status" aria-live="polite"></div>'
      . '</div>'
      . $entry->content()
    );

    return $entry;
  }

  /**
   * Save extension configuration.
   */
  public function handleConfigureAction(): void
  {
    if (!Minz_Request::isPost()) {
      return;
    }

    $prompt = Minz_Request::param('article_translator_prompt', '');
    if (trim((string)$prompt) === '') {
      $prompt = null;
    }

    FreshRSS_Context::$user_conf->article_translator_provider = Minz_Request::param('article_translator_provider', 'openai');
    FreshRSS_Context::$user_conf->article_translator_oai_url = Minz_Request::param('article_translator_oai_url', '');
    FreshRSS_Context::$user_conf->article_translator_oai_key = Minz_Request::param('article_translator_oai_key', '');
    FreshRSS_Context::$user_conf->article_translator_oai_model = Minz_Request::param('article_translator_oai_model', '');
    FreshRSS_Context::$user_conf->article_translator_prompt = $prompt;

    FreshRSS_Context::$user_conf->save();
  }

  private function escape(mixed $value): string
  {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}
