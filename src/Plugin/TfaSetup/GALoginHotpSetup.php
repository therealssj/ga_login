<?php

namespace Drupal\ga_login\Plugin\TfaSetup;

use Base32\Base32;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\encrypt\EncryptionProfileManagerInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\tfa\Plugin\TfaValidation\TfaHotp;
use Drupal\user\Entity\User;
use Drupal\user\UserDataInterface;

/**
 * HOTP setup class to setup HOTP validation.
 *
 * @TfaSetup(
 *   id = "tfa_hotp_setup",
 *   label = @Translation("TFA Hotp Setup"),
 *   description = @Translation("TFA Hotp Setup Plugin"),
 *   helpLinks = {
 *    "Google Authenticator (Android/iPhone/BlackBerry)" = "https://support.google.com/accounts/answer/1066447?hl=en",
 *    "FreeOTP (Android)" = "https://play.google.com/store/apps/details?id=org.fedorahosted.freeotp",
 *    "GAuth Authenticator (desktop)" = "https://github.com/gbraad/html5-google-authenticator"
 *   }
 * )
 */
class GALoginHotpSetup extends TfaHotp implements TfaSetupInterface {

  /**
   * Un-encrypted seed.
   *
   * @var string
   */
  protected $seed;

  /**
   * Name prefix.
   *
   * @var string
   */
  protected $namePrefix;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UserDataInterface $user_data, EncryptionProfileManagerInterface $encryption_profile_manager, EncryptServiceInterface $encrypt_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $user_data, $encryption_profile_manager, $encrypt_service);
    // Generate seed.
    $this->setSeed($this->createSeed());
    $this->namePrefix = \Drupal::config('tfa.settings')->get('name_prefix');
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupForm(array $form, FormStateInterface $form_state) {
    $help_links = $this->getHelpLinks();

    $items = [];
    foreach ($help_links as $item => $link) {
      $items[] = Link::fromTextAndUrl($item, Url::fromUri($link, ['attributes' => ['target' => '_blank']]));
    }

    $markup = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#title' => t('Install authentication code application on your mobile or desktop device:'),
    ];
    $form['apps'] = array(
      '#type' => 'markup',
      '#markup' => \Drupal::service('renderer')->render($markup),
    );
    $form['info'] = array(
      '#type' => 'markup',
      '#markup' => t('<p>The two-factor authentication application will be used during this setup and for generating codes during regular authentication. If the application supports it, scan the QR code below to get the setup code otherwise you can manually enter the text code.</p>'),
    );
    $form['seed'] = array(
      '#type' => 'textfield',
      '#value' => $this->seed,
      '#disabled' => TRUE,
      '#description' => t('Enter this code into your two-factor authentication app or scan the QR code below.'),
    );
    // QR image of seed.
    // @todo This needs to be fixed. Doesn't work right now.
    if (file_exists(drupal_get_path('module', 'tfa') . '/includes/qrcodejs/qrcode.min.js')) {
      $form['qr_image_wrapper']['qr_image'] = array(
        '#markup' => '<div id="tfa-qrcode"></div>',
      );
      $qrdata = 'otpauth://hotp/' . $this->accountName() . '?secret=' . $this->seed;
      $form['qr_image_wrapper']['qr_image']['#attached']['library'][] = array('tfa_basic', 'qrcodejs');
      $form['qr_image_wrapper']['qr_image']['#attached']['js'][] = array(
        'data' => 'jQuery(document).ready(function () { new QRCode(document.getElementById("tfa-qrcode"), "' . $qrdata . '");});',
        'type' => 'inline',
        'scope' => 'footer',
        'weight' => 5,
      );
    }
    else {
      $form['qr_image'] = array(
        '#markup' => '<img src="' . $this->getQrCodeUrl($this->seed) . '" alt="QR code for TFA setup">',
      );
    }
    // Include code entry form.
    $form = $this->getForm($form, $form_state);
    $form['actions']['login']['#value'] = t('Verify and save');
    // Alter code description.
    $form['code']['#description'] = t('A verification code will be generated after you scan the above QR code or manually enter the setup code. The verification code is six digits long.');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateSetupForm(array $form, FormStateInterface $form_state) {
    if (!$this->validate($form_state->getValue('code'))) {
      $this->errorMessages['code'] = t('Invalid application code. Please try again.');
      // $form_state->setErrorByName('code', $this->errorMessages['code']);.
      return FALSE;
    }
    $this->storeAcceptedCode($form_state->getValue('code'));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function validate($code) {
    // The counter is set as 1 because that is the initial value.
    // This ensures that things work even if we reset the application.
    $counter = $this->auth->otp->checkHotpResync(Base32::decode($this->seed), 1, $code);
    $this->setUserData('tfa', ['tfa_hotp_counter' => ++$counter], $this->uid, $this->userData);
    return ((bool) $counter);
  }

  /**
   * {@inheritdoc}
   */
  public function submitSetupForm(array $form, FormStateInterface $form_state) {
    // Write seed for user.
    $this->storeSeed($this->seed);
    return TRUE;
  }

  /**
   * Get a URL to a Google Chart QR image for a seed.
   *
   * @param string $seed
   *   Un-encrypted seed.
   *
   * @return string
   *   QR-code url.
   */
  protected function getQrCodeUrl($seed) {
    // Note, this URL is over https but does leak the seed and account
    // email address to Google. See README.txt for local QR code generation
    // using qrcode.js.
    return $this->auth->ga->getQrCodeUrl('hotp', $this->accountName(), $seed, 1);
  }

  /**
   * Create OTP seed for account.
   *
   * @return string
   *   Un-encrypted seed.
   */
  protected function createSeed() {
    return $this->auth->ga->generateRandom();
  }

  /**
   * Setter for OTP secret key.
   *
   * @param string $seed
   *   The OTP secret key.
   */
  public function setSeed($seed) {
    $this->seed = $seed;
  }

  /**
   * Get account name for QR image.
   *
   * @return string
   *   URL encoded string.
   */
  protected function accountName() {
    /** @var User $account */
    $account = User::load($this->configuration['uid']);
    return urlencode($this->namePrefix . '-' . $account->getUsername());
  }

  /**
   * {@inheritdoc}
   */
  public function getHelpLinks() {
    return $this->pluginDefinition['helpLinks'];
  }

  /**
   * {@inheritdoc}
   */
  public function getOverview($params) {
    $plugin_text = t('Validation Plugin: @plugin',
      [
        '@plugin' => str_replace(' Setup', '', $this->getLabel()),
      ]
    );
    $output = array(
      'heading' => array(
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => t('TFA application'),
      ),
      'validation_plugin' => [
        '#type' => 'markup',
        '#markup' => '<p>' . $plugin_text . '</p>',
      ],
      'description' => array(
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => t('Generate verification codes from a mobile or desktop application.'),
      ),
      'link' => array(
        '#theme' => 'links',
        '#links' => array(
          'admin' => array(
            'title' => !$params['enabled'] ? t('Set up application') : t('Reset application'),
            'url' => Url::fromRoute('tfa.validation.setup', [
              'user' => $params['account']->id(),
              'method' => $params['plugin_id'],
            ]),
          ),
        ),
      ),
    );
    return $output;
  }

}
