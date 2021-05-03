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
          </pluginClass>
        </plugins>
      </psalm>
      """

  Scenario: Regular use with single fetch
    Given I have the following code
      """
<?php
function fetchAssoc(PDO $pdo): void {
      $stmAssoc = $pdo->query('SELECT 1 as data');
      if (!$stmAssoc->rowCount()) {
          return;
      }

      $row = $stmAssoc->fetch(PDO::FETCH_ASSOC);
      echo $row['data'];
}

function fetchNum(PDO $pdo): void {
    $stmNum = $pdo->query('SELECT 1 as data');
    if (!$stmNum->rowCount()) {
        return;
    }

    $row = $stmNum->fetch(PDO::FETCH_NUM);
    echo $row[0];
}

function fetchBoth(PDO $pdo): void {
    $stmBoth = $pdo->query('SELECT 1 as data');
    if (!$stmBoth->rowCount()) {
        return;
    }

    $row = $stmBoth->fetch(PDO::FETCH_BOTH);
    assert($row[0] === $row['data']);
}
"""
    When I run psalm
    Then I see no errors

  Scenario: Regular use with single fetchAll
    Given I have the following code
"""
<?php
function fetchAll(PDO $pdo): void {
    $stmAll = $pdo->query('SELECT 1 as data');
    if (!$stmAll->rowCount()) {
        return;
    }

    $rows = $stmAll->fetchAll(PDO::FETCH_BOTH);
    assert($rows[0][0] === $rows[0]['data']);
}

function fetchObject(PDO $pdo): void {
    $stmAllObj = $pdo->query('SELECT 1 as data');
    if (!$stmAllObj->rowCount()) {
        return;
    }

    $rows = $stmAllObj->fetchAll(PDO::FETCH_OBJ);
    echo $rows[0]->data;
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
