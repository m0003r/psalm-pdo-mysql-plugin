Feature: PDOMySQL
  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm
             errorLevel="1"
             reportInfo="true"
             maxStringLength="1000"
             xmlns:xi="http://www.w3.org/2001/XInclude">
        <projectFiles>
          <directory name="."/>
        </projectFiles>
        <plugins>
          <pluginClass class="M03r\PsalmPDOMySQL\Plugin">
            <xi:include href="../../tests/Integration/_data/databases.xml" />
            <PDOClass>PDOInterface</PDOClass>
            <PDOClass>Deep\NS\PDOInterface</PDOClass>
          </pluginClass>
        </plugins>
      </psalm>
      """

  Scenario: Regular use with single fetch
    Given I have the following code
      """
<?php
namespace Deep\NS {
    interface PDOInterface {
        public function query(string $query): \PDOStatement;
    }
}

namespace {
  interface PDOInterface {
    public function query(string $query): PDOStatement;
  }

  /** @return array<numeric-string, string> */
  function fetchAssoc(PDO $pdo): array {
        $stmAssoc = $pdo->query('SELECT t_int, t_char as data FROM basic_types');
        if (!$stmAssoc->rowCount()) {
            return [];
        }

        $row = $stmAssoc->fetch(PDO::FETCH_ASSOC);
        $arr = [];
        $arr[$row['t_int']] = $row['data'];

        return $arr;
  }

  /** @return array<numeric-string, string> */
  function fetchNum(PDOInterface $pdo): array {
      $stmNum = $pdo->query('SELECT t_int, t_char as data FROM basic_types');
      if (!$stmNum->rowCount()) {
          return [];
      }

      [$int, $data] = $stmNum->fetch(PDO::FETCH_NUM);
      $arr = [];
      $arr[$int] = $data;

      return $arr;
  }

  /** @return array<numeric-string, string> */
  function fetchBoth(\Deep\NS\PDOInterface $pdo): array {
      $stmBoth = $pdo->query('SELECT t_int, t_char as data FROM basic_types');
      if (!$stmBoth->rowCount()) {
          return [];
      }

      $row = $stmBoth->fetch(PDO::FETCH_BOTH);
      $arr = [];
      $arr[$row[0]] = $row['data'];

      return $arr;
  }
}
"""
    When I run psalm
    Then I see no errors

  Scenario: Regular use with single fetchAll
    Given I have the following code
"""
<?php
/** @return array<numeric-string, string> */
function fetchAll(PDO $pdo): array {
    $stmAll = $pdo->query('SELECT t_int, t_char `data` FROM basic_types');
    if (!$stmAll->rowCount()) {
        return [];
    }

    $rows = $stmAll->fetchAll(PDO::FETCH_BOTH);
    $arr = [];
    foreach ($rows as $row) {
        $arr[$row[0]] = $row['data'];
    }

    return $arr;
}

/** @return array<numeric-string, object{id: numeric-string, data: string}> */
function fetchObject(PDO $pdo): array {
    $stmAllObj = $pdo->query('SELECT t_int `id`, t_char `data` FROM basic_types');
    if (!$stmAllObj->rowCount()) {
        return [];
    }

    $rows = $stmAllObj->fetchAll(PDO::FETCH_OBJ);
    $arr = [];
    foreach ($rows as $obj) {
        $arr[$obj->id] = $obj;
    }

    return $arr;
}
      """
    When I run psalm
    Then I see no errors

  Scenario: Regular use with fetchColumn
    Given I have the following code
"""
<?php

function fetchColumn(PDO $pdo): bool {
    $stmFC = $pdo->query('SELECT COUNT(t_int) FROM basic_types');
    if (!$stmFC->rowCount()) {
        return false;
    }

    return $stmFC->fetchColumn() > 5;
}

function fetchColumnMode(PDO $pdo): bool {
    $stmFCM = $pdo->query('SELECT COUNT(t_int) FROM basic_types');
    if (!$stmFCM->rowCount()) {
        return false;
    }

    return $stmFCM->fetch(PDO::FETCH_COLUMN) > 5;
}

function fetchColumnNum(PDO $pdo): bool {
    $stmFCN = $pdo->query('SELECT CONCAT(t_char), SUM(t_float) FROM basic_types');
    if (!$stmFCN->rowCount()) {
        return false;
    }

    return $stmFCN->fetchColumn(1) > 5;
}

function fetchColumnNumMode(PDO $pdo): bool {
    $stmFCNM = $pdo->query('SELECT CONCAT(t_char), SUM(t_float) FROM basic_types');
    if (!$stmFCNM->rowCount()) {
        return false;
    }

    return $stmFCNM->fetch(PDO::FETCH_COLUMN, 1) > 5;
}

      """
    When I run psalm
    Then I see no errors


  Scenario: Fetching non-executed statement
    Given I have the following code
"""
<?php

function unexecuted(PDO $pdo): void {
    $stm = $pdo->prepare('SELECT 1 as data');
    echo $stm->fetchColumn();
}
"""
    When I run psalm
    Then I see these errors
      | Type                    | Message                                          |
      | PDOStatementNotExecuted | Statement was not executed                       |
    And I see no other errors

  Scenario: Fetching with zero rows count
    Given I have the following code
"""
<?php

function zeroRows(PDO $pdo): void {
    $stm = $pdo->prepare('SELECT 1 as data');
    $stm->execute();
    if (!$stm->rowCount()) {
        $stm->fetchColumn();
    }
}
"""
    When I run psalm
    Then I see these errors
      | Type                    | Message                                          |
      | PDOStatementZeroRows    | PDO statement has zero remaining rows            |
    And I see no other errors
