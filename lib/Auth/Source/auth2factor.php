<?php

/**
 * @author Shoaib Ali, Catalyst IT
 *
 * 2 Step authentication module.
 *
 * Configure it by adding an entry to config/authsources.php such as this:
 *
 *      'auth2factor' => array(
 *        'auth2factor:auth2factor',
 *        'db.dsn' => 'mysql:host=db.example.com;port=3306;dbname=idpauth2factor',
 *        'db.username' => 'simplesaml',
 *        'db.password' => 'password',
 *        'mainAuthSource' => 'ldap',
 *        'uidField' => 'uid',
 *        'mailField' => 'email',
 *        'post_logout_url' => 'http://google.com', // URL to redirect to on logout. Optional
 *        'minAnswerLength' => 10, // Minimum answer length. Defaults to 0
 *        'initSecretQuestions' => array('Question 1', 'Question 2', 'Question 3') // Optional - Initialise the db with secret questions
 *        'maxCodeAge' => 60 * 5 // Maximum age for a one time code. Defaults to 5 minutes
 *        ),
 *
 * @package simpleSAMLphp
 * @version $Id$
 */

class sspmod_auth2factor_Auth_Source_auth2factor extends SimpleSAML_Auth_Source {

  /**
   * The string used to identify our step.
   */
  const STEPID = 'auth2factor.step';

  /**
   * Default minimum length of secret answer required. Can be overridden in the config
   */
  const ANSWERMINCHARLENGTH = 0;

    /**
     * Length of an SMS/Mail single use code
     */
    const SINGLEUSECODELENGTH = 8;

  /**
   * The key of the AuthId field in the state.
   */
  const AUTHID = 'sspmod_auth2factor_Auth_Source_auth2factor.AuthId';

    /**
     *   sstc-saml-loa-authncontext-profile-draft.odt
    */

    const TFAAUTHNCONTEXTCLASSREF = 'urn:oasis:names:tc:SAML:2.0:post:ac:classes:nist-800-63:3';

    /**
     * 2 Factor type constants
     */
    const FACTOR_MAIL = 'mail';
    const FACTOR_SMS = 'sms';
    const FACTOR_QUESTION = 'question';

    /**
     * Default maximum code age
     */

    const MAXCODEAGE = 300; //60 * 5

    const SALT_SPACE = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const NO_CONFUSION_SPACE = '23456789abcdefghikmnpqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private $db_dsn;
    private $db_username;
    private $db_password;
    private $site_salt;
    private $logoutURL;
    private $dbh;
    private $minAnswerLength;
    private $singleUseCodeLength;
    private $maxCodeAge;

    public $tfa_authencontextclassref;


  /**
   * Constructor for this authentication source.
   *
   * @param array $info  Information about this authentication source.
   * @param array $config  Configuration.
   */
  public function __construct($info, $config) {
    assert('is_array($info)');
    assert('is_array($config)');

    /* Call the parent constructor first, as required by the interface. */
    parent::__construct($info, $config);

    if (array_key_exists('db.dsn', $config)) {
      $this->db_dsn = $config['db.dsn'];
    }
    if (array_key_exists('db.username', $config)) {
      $this->db_username = $config['db.username'];
    }
    if (array_key_exists('db.password', $config)) {
      $this->db_password = $config['db.password'];
    }
    if (array_key_exists('post_logout_url', $config)) {
       $this->logoutURL = $config['post_logout_url'];
    } else {
       $this->logoutURL = '/logout';
    }
    if (array_key_exists('minAnswerLength', $config)) {
       $this->minAnswerLength = $config['minAnswerLength'];
    } else {
       $this->minAnserLength = self::ANSWERMINCHARLENGTH;
    }

    if (array_key_exists('singleUseCodeLength', $config)) {
        $this->singleUseCodeLength = $config['singleUseCodeLength'];
    } else {
        $this->singleUseCodeLength = self::SINGLEUSECODELENGTH;
    }

    if (array_key_exists('maxCodeAge', $config)) {
        $this->maxCodeAge = $config['maxCodeAge'];
    } else {
        $this->maxCodeAge = self::MAXCODEAGE;
    }
    $globalConfig = SimpleSAML_Configuration::getInstance();

    if ($globalConfig->hasValue('secretsalt')) {
        $this->site_salt = $globalConfig->getValue('secretsalt');
    } else {
      /* This is probably redundant, as SimpleSAMLPHP will not let you run without a salt */
      die('Auth2factor: secretsalt not set in config.php! You should set this immediately!');
    }

        $this->tfa_authencontextclassref = self::TFAAUTHNCONTEXTCLASSREF;

        try {
            $this->dbh = new PDO($this->db_dsn, $this->db_username, $this->db_password);
        } catch (PDOException $e) {
            echo 'Connection failed: ' . $e->getMessage();
            exit();
        }

        $this->createTables();

        if(array_key_exists('initSecretQuestions', $config)){
            $this->initQuestions($config['initSecretQuestions']);
        }
  }

  public function getLogoutURL() {
        return $this->logoutURL;
  }

  public function getMinAnswerLength() {
        return $this->minAnswerLength;
  }

  public function authenticate(&$state) {
    assert('is_array($state)');

    /* We are going to need the authId in order to retrieve this authentication source later. */
    $state[self::AUTHID] = $this->authId;

    $id = SimpleSAML_Auth_State::saveState($state, self::STEPID);

    $url = SimpleSAML_Module::getModuleURL('auth2factor/login.php');
    SimpleSAML_Utilities::redirect($url, array('AuthState' => $id));
  }

  public function logout(&$state) {
        assert('is_array($state)');
        $state[self::AUTHID] = $this->authId;

        $id = SimpleSAML_Auth_State::saveState($state, self::STEPID);

        $url = SimpleSAML_Module::getModuleURL('authqstep/logout.php');
        SimpleSAML_Utilities::redirect($url, array('AuthState' => $id));
  }

  //Generate a random string of a given length. Used to produce the per-question salt
    private function generateRandomString($length=15, $characters=self::SALT_SPACE) {
      $randomString = '';
      for ($i = 0; $i < $length; $i++) {
          $randomString .= $characters[rand(0, strlen($characters) - 1)];
      }
      return $randomString;
  }

  private function createTables()
  {
      /* Create table to hold questions */
      $q = "CREATE TABLE IF NOT EXISTS ssp_questions (
                  question_id INT (11) NOT NULL AUTO_INCREMENT,
                  PRIMARY KEY(question_id),
                  question_text VARCHAR(255) NOT NULL
                 );";

      $result = $this->dbh->query($q);

      /* Create table to hold answers */
      $q = "CREATE TABLE IF NOT EXISTS ssp_answers (
              answer_id INT(11) NOT NULL AUTO_INCREMENT,
              PRIMARY KEY(answer_id),
              answer_hash VARCHAR(128) NOT NULL,
              answer_salt VARCHAR(15) NOT NULL,
                  question_id INT(11) NOT NULL,
                  uid VARCHAR(60) NULL
             );";
      $result = $this->dbh->query($q);

      /* Create table to hold user preferences */
      // simple string concatenation is safe here because constants
      $q = "CREATE TABLE IF NOT EXISTS ssp_user_2factor (
              uid VARCHAR(60) NOT NULL,
              PRIMARY KEY(uid),
              challenge_type ENUM('".self::FACTOR_QUESTION."', '".self::FACTOR_SMS."', '".self::FACTOR_MAIL."') NOT NULL,
              last_code VARCHAR(10) NULL,
              last_code_stamp TIMESTAMP NULL,
              UNIQUE KEY uid (uid)
             );";
      $result = $this->dbh->query($q);
  }

    private function initQuestions($questions){
        // Not sure if this is the correct way to use assert
        if(assert('is_array($questions)')){
            // make sure table is empty
            if($this->emptyTable("ssp_questions")) {
                foreach($questions as $q){
                    $q = "INSERT INTO ssp_questions(question_text) VALUES ('".addslashes($q)."');";
                    $this->dbh->query($q);
                }
            }
        }
    }

    private function emptyTable($table){
        $q = "SELECT COUNT(*) as records_count FROM $table";
        $result = $this->dbh->query($q);
        $row = $result->fetch();
        $records_count =  $row["records_count"];
        return ($records_count == 0)? TRUE : FALSE;
    }

    /**
     * This method determines if the user's answers are registered
     *
     * @param int $uid
     * @return bool
     */

  public function isRegistered($uid)
  {
        if (strlen($uid) > 0) {
            $q = $this->dbh->prepare("SELECT COUNT(*) as registered_count FROM ssp_answers WHERE uid=:uid");
            $result = $q->execute([':uid' => $uid]);
            $row = $q->fetch();
            $registered =  $row["registered_count"];
            if ($registered >= 3){
                SimpleSAML_Logger::debug('User '.$uid.' is registered, '.$registered.' answers');
                return TRUE;
            } else {

                // Can just return $this->hasMailCode($uid);
                if ($this->hasMailCode($uid)) {
                  return TRUE;
                  SimpleSAML_Logger::debug('User '.$uid.' is registered for PIN code');
                }
                SimpleSAML_Logger::debug('User '.$uid.' is NOT registered, '.$registered.' answers');
                return FALSE;
            }
        } else {
            return FALSE;
        }
  }

    /**
     * Get user preferences from database
     *
     * @param int $uid
     * @return array
     */
    public function get2FactorFromUID($uid){
        $q = $this->dbh->prepare("SELECT * FROM ssp_user_2factor WHERE uid=:uid");
        $result = $q->execute([':uid' => $uid]);
        $rows = $q->fetchAll();
        if(empty($rows)){
            SimpleSAML_Logger::debug('auth2factor: use has no default prefs');
            return array('uid' => $uid, 'challenge_type' => self::FACTOR_QUESTION);
        }
        SimpleSAML_Logger::debug('auth2factor: using method '. $rows[0]['challenge_type']);
        return $rows[0];
    }

    /**
     * Saves user 2nd Factor preferences to database
     *
     * @param int $uid
     * @param string $type
     * @return bool
     */
    public function set2Factor($uid, $type, $code="") {
        $q = $this->dbh->prepare("INSERT INTO ssp_user_2factor (uid, challenge_type, last_code, last_code_stamp)
                                  VALUES (:uid,
                                          :type,
                                          :code,
                                          NOW()) ON DUPLICATE KEY UPDATE challenge_type=:type, last_code=:code, last_code_stamp=NOW();");

        $result = $q->execute([':uid' => $uid, ':type' => $type, ':code' => $code]);
        SimpleSAML_Logger::debug('auth2factor: ' . $uid . ' set preferences: '. $type . ' code:' . $code);

        return $result;
    }

    public function sendMailCode($uid, $email) {
        $code = $this->generateRandomString($this->singleUseCodeLength, self::NO_CONFUSION_SPACE);
        $this->set2Factor($uid, self::FACTOR_MAIL, $code);
        SimpleSAML_Logger::debug('auth2factor: sending '.self::FACTOR_MAIL.' code: '. $code);
        mail($email, 'Code = '.$code, '');
    }

    public function hasMailCode($uid) {
        $q = $this->dbh->prepare("SELECT uid, last_code, last_code_stamp FROM ssp_user_2factor WHERE uid=:uid ORDER BY last_code_stamp DESC;");
        $result = $q->execute([':uid' => $uid]);
        $rows = $q->fetchAll();
        if (count($rows) == 0) {
            SimpleSAML_Logger::debug('User '.$uid.' has no challenge');
            return false;
        } else if (count($rows) > 1) {
            SimpleSAML_Logger::debug('User '.$uid.' has multiple prefs rows');
        }

        $age = date_diff(new DateTime(), date_create($rows[0]['last_code_stamp']));
        $age_s = $age->s;
        $age_s += $age->i * 60;
        $age_s += $age->h * 60 * 60;
        $age_s += $age->d * 60 * 60 * 24;
        $age_s += $age->m * 60 * 60 * 24 * 30; // Codes don't live for more than a few minutes, so this is an OK assumption
        $age_s += $age->y * 60 * 60 * 24 * 30 * 12;
        SimpleSAML_Logger::debug('User code age '. $age_s);

        if (strlen($rows[0]['last_code']) != $this->singleUseCodeLength) {
            SimpleSAML_Logger::debug('User '.$uid.' stored code is too short');
            return false;
        } else if ($age_s > $this->maxCodeAge) {
            SimpleSAML_Logger::debug('User '.$uid.' stored code has expired');
            return false;
        } else {
            return true;
        }
    }

    public function getQuestions(){
        $q = "SELECT * FROM ssp_questions;";
        $result = $this->dbh->query($q);
        $row = $result->fetchAll();

        if(empty($row)){
            return false;
        }
        return $row;
    }

    public function getAnswersFromUID($uid)
    {
        $q = $this->dbh->prepare("SELECT * FROM ssp_answers WHERE uid=:uid");
        $result = $q->execute([':uid' => $uid]);
        $rows = $q->fetchAll();
        return $rows;
    }

    public function getRandomQuestion($uid){
        $q = $this->dbh->prepare("SELECT ssp_answers.question_id, ssp_questions.question_text FROM ssp_answers, ssp_questions WHERE ssp_answers.uid=:uid AND ssp_answers.question_id = ssp_questions.question_id;");
        $result = $q->execute([':uid' => $uid]);

        $rows = $q->fetchAll();
        // array_rand is quicker then SQL ORDER BY RAND()
        $random_question = $rows[array_rand($rows)];
        // TODO this question needs to be made persistent
        // so that user is challenged for same random question
        return array_unique($random_question);
    }

    private function calculateAnswerHash($answer, $siteSalt, $answerSalt) {
      return hash('sha512', $siteSalt.$answerSalt.strtolower($answer));
    }

    /**
     * Saves user submitted answers in to database
     *
     * @param int $uid
     * @param array $answers
     * @param array $questions
     * @return bool
     */

    public function registerAnswers($uid,$answers, $questions) {

        // This check is probably not needed
        if (empty($answers) || empty($questions) || empty($uid)) return FALSE;
        $question_answers = array_combine($answers, $questions);

        foreach ($question_answers as $answer => $question) {
            // Check that the answer meets the length requirements
            if ( (strlen($answer) >= $this->minAnswerLength) && $question > 0) {
                $answer_salt = $this->generateRandomString();
                $answer_hash = $this->calculateAnswerHash($answer, $this->site_salt, $answer_salt);
                $q = $this->dbh->prepare("INSERT INTO ssp_answers (answer_salt, answer_hash, question_id, uid) VALUES (:answer_salt, :answer_hash, :question, :uid)");

                $result = $q->execute([':answer_salt' => $answer_salt,
                                       ':answer_hash' => $answer_hash,
                                       ':question' => $question,
                                       ':uid' => $uid]);

                var_dump($result);
                SimpleSAML_Logger::debug('authqstep: ' . $uid . ' registered his answer: '. $answer . ' for question_id:' . $question);
            } else {
                $result = FALSE;
            }
        }
        return $result;
    }


    /**
     * Verify user submitted answer against their question
     *
     * @param int $uid
     * @param int $question_id
     * @param string $answer
     * @return bool
     */
    public function verifyAnswer($uid, $question_id, $answer){
        $answers = self::getAnswersFromUID($uid);
        $match = FALSE;

        foreach($answers as $a){
          if ($question_id == $a["question_id"]) {
                $answer_salt = $a['answer_salt'];
                $submitted_hash = $this->calculateAnswerHash($answer, $this->site_salt, $answer_salt);
                if($submitted_hash == $a["answer_hash"]) {
                    $match = TRUE;
                    break;
                }
          }
        }
        return $match;
    }

    public function verifyChallenge($uid, $answer) {
        if ($this->hasMailCode($uid)) {
            $q = $this->dbh->prepare("SELECT uid, last_code, last_code_stamp FROM ssp_user_2factor WHERE uid=:uid ORDER BY last_code_stamp DESC;");
            $result = $q->execute([':uid' => $uid]);
            $rows = $q->fetchAll();
            if ($rows[0]['last_code'] === trim($answer)) {
                SimpleSAML_Logger::debug('User '.$uid.' passed good code');
                $q = $this->dbh->prepare("UPDATE ssp_user_2factor SET last_code=NULL,last_code_stamp=NULL WHERE uid=:uid;");
                $result = $q->execute([':uid' => $uid]);
                return true;
            } else {
                SimpleSAML_Logger::debug('User '.$uid.' passed bad code. "'.$rows[0]['last_code'].'" !== "'.$answer.'"');
                return false;
            }
        } else {
            SimpleSAML_Logger::debug('User '.$uid.' does not have a code');
            return false;
        }
    }

}

?>
