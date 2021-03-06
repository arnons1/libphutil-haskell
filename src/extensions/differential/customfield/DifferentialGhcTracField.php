<?php

/**
 * Extends Differential with a 'Trac Issues' field for SQream.
 */
final class DifferentialSqreamTracField
  extends DifferentialStoredCustomField {

  private $error;

  /* -- Core custom field descriptions -------------------------------------- */
  public function getFieldKey() {
    return 'differential:sqream-trac';
  }

  public function getFieldName() {
    // Rendered in 'Config > Differential > differential.fields'
    return pht('SQream Trac Issues');
  }

  public function getFieldDescription() {
    // Rendered in 'Config > Differential > differential.fields'
    return pht('Lists associated SQream Trac issues.');
  }

  /* -- Field properties ---------------------------------------------------- */
  public function canDisableField() {
    // Rendered in 'Config > Differential > differential.fields'
    return true;
  }

  public function shouldAppearInPropertyView() {
    return true; // NOTE: Only appears for 'rS', see below.
  }

  public function shouldAppearInEditView() {
    return true;
  }

  public function shouldAppearInCommitMessage() {
    return true;
  }

  public function shouldAllowEditInCommitMessage() {
    return true;
  }

  public function shouldAppearInConduitDictionary() {
    return true;
  }

  public function shouldOverwriteWhenCommitMessageIsEdited() {
    return true;
  }

  // Possible alternative labels
  public function getCommitMessageLabels() {
    return array(
      'Trac',
      'Trac Issue',
      'Trac Issues',
      'SQream Trac',
      'SQream Trac Issue',
      'SQream Trac Issues',
    );
  }

  public function shouldAppearInCommitMessageTemplate() {
    return true;
  }

  // Rendered when you run 'arc diff'
  public function renderCommitMessageLabel() {
    return 'SQream Trac Issues';
  }

  // Rendered in the UI when viewing a revision
  public function renderPropertyViewLabel() {
    return pht('Trac Issues');
  }

  /* -- Transactions -------------------------------------------------------- */
  public function shouldAppearInApplicationTransactions() {
    return true;
  }

  public function getOldValueForApplicationTransactions() {
    return array_unique(nonempty($this->getValue(), array()));
  }

  public function getNewValueForApplicationTransactions() {
    return array_unique(nonempty($this->getValue(), array()));
  }

  public function getApplicationTransactionTitle(
    PhabricatorApplicationTransaction $xaction) {
    $author_phid = $xaction->getAuthorPHID();
    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    return pht(
      '%s updated the Trac tickets for this revision.',
      $xaction->renderHandleLink($author_phid));
  }

  public function getApplicationTransactionTitleForFeed(
    PhabricatorApplicationTransaction $xaction) {

    $object_phid = $xaction->getObjectPHID();
    $author_phid = $xaction->getAuthorPHID();
    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    return pht(
      '%s updated the Trac tickets for %s.',
      $xaction->renderHandleLink($author_phid),
      $xaction->renderHandleLink($object_phid));
  }

  public function validateApplicationTransactions(
    PhabricatorApplicationTransactionEditor $editor,
    $type,
    array $xactions) {

    $this->error = null;
    $errors = parent::validateApplicationTransactions(
      $editor,
      $type,
      $xactions);

    foreach ($xactions as $xaction) {
      $old = $xaction->getOldValue();
      $new = $xaction->getNewValue();

      $add = array_diff($new, $old);
      if (!$add) {
        continue;
      }

      // Validate issue references
      foreach ($new as $id) {
        if (!preg_match('/#(\d+)/', $id)) {
          $this->error = pht('Invalid');
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid issue reference'),
            pht('References to SQream Trac tickets may only take the form '.
                '`#XYZ` where `XYZ` refers to an issue number, but you '.
                'specified "%s".', $id),
            $xaction);
        }
      }
    }

    return $errors;
  }

  /* -- Storage ------------------------------------------------------------- */
  public function readValueFromRequest(AphrontRequest $request) {
    $this->setValue($request->getStrList($this->getFieldKey()));
    return $this;
  }

  public function getValueForStorage() {
    return json_encode($this->getValue());
  }

  public function setValueFromStorage($value) {
    try {
      $this->setValue(phutil_json_decode($value));
    } catch (PhutilJSONParserException $ex) {
      $this->setValue(array());
    }
    return $this;
  }

  /* -- Parsing commits ----------------------------------------------------- */
  public function parseValueFromCommitMessage($value) {
    // return early if the user didn't provide anything
    if (!strlen($value)) {
      return array();
    }

    return preg_split('/[\s,]+/', $value, $limit = -1, PREG_SPLIT_NO_EMPTY);
  }

  public function validateCommitMessageValue($value) {
    foreach ($value as $id) {
      if (!preg_match('/#(\d+)/', $id)) {
        throw new DifferentialFieldValidationException(
          pht('References to SQream Trac issues may only take the form '.
              '`#XYZ` where `XYZ` refers to an issue number.'));
      }
    }
  }

  public function readValueFromCommitMessage($value) {
    $this->setValue($value);
    return $this;
  }

  /* -- Rendering ----------------------------------------------------------- */
  public function renderEditControl(array $handles) {
    return id(new AphrontFormTextControl())
      ->setLabel(pht('SQream Trac Issues'))
      ->setCaption(
        pht('Example: %s', phutil_tag('tt', array(), '#7602, #2345')))
      ->setName($this->getFieldKey())
      ->setValue(implode(', ', nonempty($this->getValue(), array())))
      ->setError($this->error);
  }

  public function renderCommitMessageValue(array $handles) {
    $value = $this->getValue();
    if (!$value) {
      return null;
    }
    return implode(', ', $value);
  }

  public function renderPropertyViewValue(array $handles) {
    $links = array();
    $match = null;

    // Don't show for non-SQream repositories.
    if (!$this->isSqreamRepository()) {
      return null;
    }

    foreach ($this->getValue() as $ref) {
      if (!preg_match('/#(\d+)/', $ref, $match)) {
        $links[] = pht($ref);
      }
      else {
        $num = $match[1];
        $links[] = phutil_tag('a', array(
          'href' => 'http://192.168.0.61:8080/ticket/'.$num,
        ), $ref);
      }
    }

    // Return null if there aren't links, so we don't stick empty HTML into the
    // field causing it to always render.
    if (empty($links)) {
      return null;
    }
    else {
      return phutil_implode_html(phutil_tag('br'), $links);
    }
  }

  /* -- Private APIs -------------------------------------------------------- */
  private function isSqreamRepository() {
    $repo = $this->getObject()->getRepository();
    if ($repo === null) {
      return false;
    }

    return ($repo->getMonogram() === 'rS');
  }
}

// Local Variables:
// fill-column: 80
// indent-tabs-mode: nil
// c-basic-offset: 2
// buffer-file-coding-system: utf-8-unix
// End:
