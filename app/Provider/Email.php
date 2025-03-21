<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Config;
use Mailgun\Mailgun;

define('EMAIL_TIMEOUT', 300);

trait Email {

  private function _start_email($me, $details) {

    $code = random_string();
    redis()->setex('indielogin:email:'.$code, EMAIL_TIMEOUT, json_encode($details));

    $_SESSION['login_request']['profile'] = $details['email'];

    return new HtmlResponse(view('auth/email', [
      'title' => 'Log In via Email',
      'code' => $code,
      'email' => $details['email']
    ]));
  }

  public function send_email(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $params = $request->getParsedBody();

    $devlog = make_logger('dev');
    $userlog = make_logger('user');

    $login = redis()->get('indielogin:email:'.$params['code']);

    if(!$login) {
      return new HtmlResponse(view('auth/email-error', [
        'title' => 'Error',
        'error' => 'The session expired',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
    }

    $login = json_decode($login, true);

    $usercode = random_user_code();

    // 4 chars will take ~3000 requests per second to attack the code that is valid for 5 minutes.
    // TODO: add rate limiting on $params['code'] to prevent this.

    redis()->setex('indielogin:email:usercode:'.$params['code'], EMAIL_TIMEOUT, $usercode);

    $login_url = getenv('BASE_URL').'auth/verify_email_code?'.http_build_query([
      'code' => $params['code'],
      'usercode' => $usercode,
    ]);

    $mg = Mailgun::create(getenv('MAILGUN_KEY'));
    $result = $mg->messages()->send(getenv('MAILGUN_DOMAIN'), [
      'from'     => getenv('MAILGUN_FROM'),
      'to'       => $login['email'],
      'subject'  => 'Your '.getenv('APP_NAME').' Code: '.$usercode,
      'text'     => "Enter the code below to sign in: \n\n$usercode\n"
    ]);

    return new HtmlResponse(view('auth/email-enter-code', [
      'title' => 'Log In via Email',
      'code' => $params['code'],
    ]));
  }

  public function verify_email_code(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $params = $request->getParsedBody();

    $devlog = make_logger('dev');
    $userlog = make_logger('user');

    $login = redis()->get('indielogin:email:'.$params['code']);

    if(!$login) {
      return new HtmlResponse(view('auth/email-error', [
        'title' => 'Error',
        'error' => 'The session expired',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
    }

    $login = json_decode($login, true);

    $usercode = redis()->get('indielogin:email:usercode:'.$params['code']);

    // Check that the code they entered matches the code that was stored

    if(strtolower(str_replace('-','',$usercode)) == strtolower(str_replace('-','',$params['usercode']))) {
      return $this->_finishAuthenticate();
    } else {
      $k = 'indielogin:email:usercode:attempts:'.$params['code'];
      $current_attempts = (redis()->get($k) ?: 0);

      // Allow only 4 failed attempts, then start over.
      // This prevents brute forcing the code.      
      if($current_attempts >= 3) {
        redis()->del('indielogin:email:usercode:'.$params['code']);
        redis()->del('indielogin:email:'.$params['code']);
        redis()->del($k);

        return new HtmlResponse(view('auth/email-error', [
          'title' => 'Error',
          'error' => 'The session expired',
          'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
        ]));
      }

      // Increment the counter of failed attempts
      redis()->setex($k, EMAIL_TIMEOUT, $current_attempts+1);

      return new HtmlResponse(view('auth/email-enter-code', [
        'title' => 'Log In via Email',
        'code' => $params['code'],
        'error' => 'You entered an incorrect code. Please try again.',
      ]));
    }

  }


}

