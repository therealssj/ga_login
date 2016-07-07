<?php

namespace Drupal\services_ga_login\Plugin\ServiceDefinition;


use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\services\ServiceDefinitionBase;
use Drupal\tfa\TfaValidationPluginManager;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @ServiceDefinition(
 *   id = "hotp_login",
 *   methods = {
 *     "POST"
 *   },
 *
 *   title = @Translation("HOTP login"),
 *   description = @Translation("Allows users to login through HOTP authentication."),
 *   category = @Translation("Security"),
 *   path = "auth/hotp"
 * )
 */
class HOTPLogin extends ServiceDefinitionBase implements ContainerFactoryPluginInterface {
  /**
   * Validation plugin manager.
   *
   * @var \Drupal\tfa\TfaValidationPluginManager
   */
  protected $tfaValidationManager;

  /**
   * TOTP validation plugin object.
   *
   * @var \Drupal\tfa\Plugin\TfaValidationInterface
   */
  protected $hotpValidationPlugin;

  /**
   * The validation plugin object.
   *
   * @var \Drupal\tfa\Plugin\TfaValidationInterface
   */
  protected $userData;

  /**
   * TOTPLogin constructor.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\user\UserDataInterface $user_data
   * @param \Drupal\tfa\TfaValidationPluginManager $tfa_validation_manager
   */
  public function __construct($configuration, $plugin_id, $plugin_definition, UserDataInterface $user_data, TfaValidationPluginManager $tfa_validation_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->userData = $user_data;
    $this->tfaValidationManager = $tfa_validation_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('user.data'),
      $container->get('plugin.manager.tfa.validation')
    );
  }

// @todo Figure out why this results in 403.
//  /**
//   * {@inheritdoc}
//   */
//  public function processRoute(Route $route) {
//    $route->setRequirement('_user_is_logged_in', 'FALSE');
//  }

  /**
   * {@inheritdoc}
   */
  public function processRequest(Request $request, RouteMatchInterface $route_match, SerializerInterface $serializer) {
    $uid = $request->get('id');
    $code = $request->get('code');

    $this->hotpValidationPlugin = $this->tfaValidationManager->createInstance('tfa_hotp', ['uid' => $uid]);
    $valid = $this->hotpValidationPlugin->validateRequest($code);
    if ($this->hotpValidationPlugin->isAlreadyAccepted()) {
      throw new AccessDeniedHttpException('Invalid code, it was recently used for a login. Please try a new code.');
    }
    elseif (!$valid) {
      throw new AccessDeniedHttpException('Invalid application code. Please try again.');
    }
    else {
      return 1;
    }
  }

}