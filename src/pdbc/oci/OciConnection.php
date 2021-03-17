<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\oci;

use dev\winterframework\pdbc\CallableStatement;
use dev\winterframework\pdbc\ex\CannotGetConnectionException;
use dev\winterframework\pdbc\ex\SQLException;
use dev\winterframework\pdbc\PreparedStatement;
use dev\winterframework\pdbc\ResultSet;
use dev\winterframework\pdbc\Statement;
use dev\winterframework\pdbc\support\AbstractConnection;
use dev\winterframework\pdbc\support\DatabaseMetaData;
use dev\winterframework\txn\Savepoint;
use PDO;
use Throwable;

class OciConnection extends AbstractConnection {
    private mixed $oci = null;
    private array $savePoints = [];
    private array $clientInfo = [];
    private int $txnCounter = 0;
    private int $commitMode = OCI_COMMIT_ON_SUCCESS;

    public function __construct(
        private string $dsn,
        private ?string $username = null,
        private ?string $password = null,
        private array $options = []
    ) {
        $this->doConnect();
    }

    public function getResource(): mixed {
        $this->assertConnectionOpen();
        return $this->oci;
    }

    private function doConnect() {
        $lang = isset($this->options['NLS_LANG']) ? $this->options['NLS_LANG'] : 'AMERICAN_AMERICA.UTF8';
        try {
            if ($this->options[PDO::ATTR_PERSISTENT]) {
                $this->oci = oci_pconnect(
                    $this->username,
                    $this->password,
                    $this->dsn,
                    $lang
                );
            } else {
                $this->oci = oci_connect(
                    $this->username,
                    $this->password,
                    $this->dsn,
                    $lang
                );
            }

            if (isset($this->options[PDO::ATTR_PREFETCH])) {
                oci_set_prefetch($this->oci, $this->options[PDO::ATTR_PREFETCH]);
            }
        } catch (Throwable $e) {
            throw new CannotGetConnectionException('Could not connect to datasource', 0, $e);
        }
    }

    private function assertConnectionOpen(): void {
        if (!isset($this->oci)) {
            throw new SQLException('Oci connection already been closed, '
                . 'Could not perform operation on closed connection.');
        }
    }

    /**
     * --------------------------
     * Implemented Methods
     */
    public function close(): void {
        if ($this->oci) {
            oci_close($this->oci);
        }
        $this->oci = null;
    }

    public function getOci(): mixed {
        return $this->oci;
    }

    public function getCommitMode(): int {
        return $this->commitMode;
    }

    public function isClosed(): bool {
        return is_null($this->oci);
    }

    public function getDriverType(): mixed {
        return 'oci8';
    }

    public function getSchema(): string {
        $this->assertConnectionOpen();

        return $this->username;
    }

    public function createStatement(
        int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ): Statement {
        $this->assertConnectionOpen();

        $stmt = new OciQueryStatement($this);
        $stmt->setResultSetType($resultSetType);
        return $stmt;
    }

    public function prepareStatement(
        string $sql,
        int $autoGeneratedKeys = Statement::NO_GENERATED_KEYS,
        array $columnIdxOrNameOrs = [],
        int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ): PreparedStatement {
        $this->assertConnectionOpen();

        $stmt = new OciPreparedStatement($this, $sql);
        $stmt->setResultSetType($resultSetType);
        return $stmt;
    }

    public function prepareCall(
        string $sql,
        int $resultSetType = ResultSet::TYPE_FORWARD_ONLY
    ): CallableStatement {
        $this->assertConnectionOpen();

        $stmt = new OciCallableStatement($this, $sql);
        $stmt->setResultSetType($resultSetType);
        return $stmt;
    }

    public function getMetaData(): DatabaseMetaData {
        // TODO:
        return new DatabaseMetaData();
    }

    protected function inTransaction(): bool {
        return $this->txnCounter > 0;
    }

    public function beginTransaction(): void {
        $this->assertConnectionOpen();

        $this->txnCounter++;
        if ($this->txnCounter == 1) {
            $this->commitMode = OCI_NO_AUTO_COMMIT;
        }
    }

    public function commit(): void {
        $this->assertConnectionOpen();

        $this->txnCounter--;
        if ($this->txnCounter == 0) {
            oci_commit($this->oci);
            $this->commitMode = OCI_COMMIT_ON_SUCCESS;
        }
    }

    public function rollback(Savepoint $savepoint = null): void {
        $this->assertConnectionOpen();
        if (is_null($savepoint)) {
            $this->txnCounter--;
            if ($this->txnCounter == 0) {
                oci_rollback($this->oci);
                $this->commitMode = OCI_COMMIT_ON_SUCCESS;
            }
        } else {
            $this->releaseSavepoint($savepoint);

//            if (empty($this->savePoints)) {
//                oci_rollback($this->oci);
//                $this->txnCounter = 0;
//            }
        }
    }

    public function setSavepoint(string $name = null): Savepoint {
        $this->assertConnectionOpen();
        if (is_null($name) || empty($name)) {
            $name = uniqid('ocisp_', true);
        }

        if (isset($this->savePoints[$name])) {
            throw new SQLException('Savepoint already exist with name ' . $name);
        }

        if (!preg_match('/^[a-zA-Z]+\w*$/', $name)) {
            throw new SQLException('Invalid Savepoint name ' . $name);
        }

        $sp = new Savepoint($name);
        $stid = oci_parse($this->oci, 'SAVEPOINT ' . $name);
        oci_execute($stid, OCI_NO_AUTO_COMMIT);
        oci_free_statement($stid);

        $this->savePoints[$name] = $sp;

        return $sp;
    }

    public function releaseSavepoint(Savepoint $savepoint): void {
        $this->assertConnectionOpen();

        $stid = oci_parse($this->oci, 'ROLLBACK TO SAVEPOINT ' . $savepoint->getName());
        oci_execute($stid, OCI_NO_AUTO_COMMIT);
        oci_free_statement($stid);
        unset($this->savePoints[$savepoint->getName()]);
    }

    public function isSavepointAllowed(): bool {
        return true;
    }

    public function setClientInfo(array $keyPair): void {
        throw new SQLException('Driver does not support this function ' . __METHOD__);
    }

    public function setSchema(string $schema): void {
        throw new SQLException('Driver does not support this function ' . __METHOD__);
    }

    public function setClientInfoValue(string $name, string $value): void {
        $this->assertConnectionOpen();
        $this->clientInfo[$name] = $value;
        oci_set_client_info($this->oci, $this->clientInfo[$name]);
    }

    public function getClientInfo(): array {
        return $this->clientInfo;
    }

    public function getClientInfoValue(string $name): string {
        return isset($this->clientInfo[$name]) ? $this->clientInfo[$name] : '';
    }

}