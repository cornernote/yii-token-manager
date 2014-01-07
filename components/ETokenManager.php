<?php

/**
 * @author Brett O'Donnell <cornernote@gmail.com>
 * @copyright 2013 Mr PHP
 * @link https://github.com/cornernote/yii-token-manager
 * @license BSD-3-Clause https://raw.github.com/cornernote/yii-token-manager/master/license.txt
 *
 * @package yii-token-manager
 */
class ETokenManager extends CComponent
{

    /**
     * @var string the ID of the {@link CDbConnection} application component. If not set,
     * a SQLite3 database will be automatically created and used. The SQLite database file
     * is <code>protected/runtime/token-TokenVersion.db</code>.
     */
    public $connectionID;

    /**
     * @var string name of the DB table to store token content. Defaults to 'Token'.
     * @see autoCreateTokenTable
     */
    public $tokenTableName = 'token';

    /**
     * @var boolean whether the token DB table should be created automatically if it does not exist. Defaults to true.
     * If you already have the table created, it is recommended you set this property to be false to improve performance.
     * @see tokenTableName
     */
    public $autoCreateTokenTable = true;

    /**
     * @var CDbConnection the DB connection instance
     */
    private $_db;

    /**
     * @return string the version of Yii Token Manager
     */
    public static function getVersion()
    {
        return '1.0.0';
    }

    /**
     * Initializes this application component.
     *
     * This method is required by the {@link IApplicationComponent} interface.
     * It ensures the existence of the token DB table.
     * It also removes expired data items from the table.
     */
    public function init()
    {
        $db = $this->getDbConnection();
        $db->setActive(true);
        if ($this->autoCreateTokenTable) {
            $sql = "DELETE FROM {$this->tokenTableName} WHERE (expire>0 AND expire<" . time() . ") OR (uses_allowed>0 AND uses_remaining<1)";
            try {
                $db->createCommand($sql)->execute();
            } catch (Exception $e) {
                $this->createTokenTable($db, $this->tokenTableName);
            }
        }
    }


    /**
     * Creates the token DB table.
     * @param CDbConnection $db the database connection
     * @param string $tableName the name of the table to be created
     */
    protected function createTokenTable($db, $tableName)
    {
        $driver = $db->getDriverName();
        $file = dirname(__DIR__) . '/migrations/' . $this->tokenTableName . '.' . $db->getDriverName();
        $pdo = $this->getDbConnection()->pdoInstance;
        $sql = file_get_contents($file);
        $sql = rtrim($sql);
        $sqls = preg_replace_callback("/\((.*)\)/", create_function('$matches', 'return str_replace(";"," $$$ ",$matches[0]);'), $sql);
        $sqls = explode(";", $sqls);
        foreach ($sqls as $sql) {
            if (!empty($sql)) {
                $sql = str_replace(" $$$ ", ";", $sql) . ";";
                $pdo->exec($sql);
            }
        }
    }

    /**
     * @return CDbConnection the DB connection instance
     * @throws CException if {@link connectionID} does not point to a valid application component.
     */
    public function getDbConnection()
    {
        if ($this->_db !== null)
            return $this->_db;
        elseif (($id = $this->connectionID) !== null) {
            if (($this->_db = Yii::app()->getComponent($id)) instanceof CDbConnection)
                return $this->_db;
            else
                throw new CException(Yii::t('yii', 'ETokenManager.connectionID "{id}" is invalid. Please make sure it refers to the ID of a CDbConnection application component.',
                    array('{id}' => $id)));
        }
        else {
            $dbFile = Yii::app()->getRuntimePath() . DIRECTORY_SEPARATOR . 'token-' . ETokenManager::getVersion() . '.db';
            return $this->_db = new CDbConnection('sqlite:' . $dbFile);
        }
    }

    /**
     * Sets the DB connection used by the token component.
     * @param CDbConnection $value the DB connection instance
     * @since 1.1.5
     */
    public function setDbConnection($value)
    {
        $this->_db = $value;
    }

    /**
     * @param $expires
     * @param $model_name
     * @param $model_id
     * @param $uses_allowed
     * @return string
     */
    public function createToken($expires, $model_name, $model_id, $uses_allowed = 0)
    {
        $plain = md5($this->hashToken(uniqid(true)));
        $token = $this->hashToken($plain);
        $this->getDbConnection()->getCommandBuilder()->createInsertCommand($this->tokenTableName, array(
            'uses_allowed' => $uses_allowed,
            'uses_remaining' => $uses_allowed,
            'expires' => $expires,
            'model_name' => $model_name,
            'model_id' => $model_id,
            'token' => $token,
            'created' => time(),
        ))->execute();
        return $plain;
    }

    /**
     * @param $model_name
     * @param $model_id
     * @param $plain
     * @return YdToken
     */
    public function checkToken($model_name, $model_id, $plain)
    {
        // get the token
        $sql = "SELECT id, token, uses_allowed, uses_remaining, expires FROM {$this->tokenTableName} WHERE model_name=:model_name AND model_id=:model_id ORDER BY created DESC, id DESC LIMIT 1";
        $token = $this->getDbConnection()->createCommand($sql)->queryRow(array(
            ':model_name' => $model_name,
            ':model_id' => $model_id,
        ));
        $log = 'checkToken failed [' . $model_name . '|' . $model_id . '|' . $plain . '] - ';
        // check for valid token
        if (!$token) {
            Yii::log($log . 'no token found');
            return false;
        }
        // check uses remaining
        if ($token['uses_allowed'] > 0 && $token['uses_remaining'] < 1) {
            Yii::log($log . 'no uses remaining');
            return false;
        }
        // check expires
        if ($token['expires'] > 0 && $token['expires'] <= time()) {
            Yii::log($log . 'token is expired');
            return false;
        }
        // check token plain
        if (!$token->verifyToken($plain)) {
            Yii::log($log . 'token is invalid');
            return false;
        }
        return $token;
    }

    /**
     * @param $model
     * @param $model_id
     * @param $plain
     * @return bool
     */
    public function useToken($model, $model_id, $plain)
    {
        $token = $this->checkToken($model, $model_id, $plain);
        if (!$token) {
            return false;
        }
        if ($token['uses_allowed'] > 0) {
            // deduct from uses remaining
            $sql = "UPDATE {$this->tokenTableName} SET uses_remaining = :uses_remaining WHERE id = :id";
            $this->getDbConnection()->createCommand($sql)->execute(array(
                ':id' => $token['id'],
                ':uses_remaining' => $token['uses_remaining'] - 1,
            ));
        }
        return true;
    }

    /**
     * @param $plain
     * @param null $encrypted
     * @return boolean validates a token
     */
    public function verifyToken($plain, $encrypted = null)
    {
        $encrypted = $encrypted ? $encrypted : $this->token;
        if (!$plain || !$encrypted) {
            return false;
        }
        return CPasswordHelper::verifyPassword($plain, $encrypted);
    }

    /**
     * @param $plain
     * @return string creates a token hash
     */
    public function hashToken($plain)
    {
        return CPasswordHelper::hashPassword($plain);
    }

}