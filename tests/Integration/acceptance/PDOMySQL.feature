Feature: PDOMySQL
  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true"
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

  Scenario: Dummy
    Given I have the following code
      """

      """
    When I run psalm
    Then I see no errors

