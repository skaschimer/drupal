<?php

namespace Drupal\Core\TypedData\Plugin\DataType;

use Drupal\Core\Serialization\Attribute\JsonSchema;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\Type\DurationInterface;

/**
 * The duration ISO8601 data type.
 *
 * The plain value of this data type is an ISO8601 duration string.
 */
#[DataType(
  id: "duration_iso8601",
  label: new TranslatableMarkup("Duration")
)]
class DurationIso8601 extends StringData implements DurationInterface {

  /**
   * {@inheritdoc}
   */
  public function getDuration() {
    if ($this->value) {
      // @todo Add support for negative intervals on top of the DateInterval
      // constructor.
      return new \DateInterval($this->value);
    }
  }

  /**
   * {@inheritdoc}
   */
  #[JsonSchema(['type' => 'string', 'format' => 'duration'])]
  public function getDurationAsIso8601Abnf(): string {
    return $this->value;
  }

  /**
   * {@inheritdoc}
   */
  #[JsonSchema(['type' => 'string', 'format' => 'duration'])]
  public function getCastedValue() {
    return parent::getCastedValue();
  }

  /**
   * {@inheritdoc}
   */
  public function setDuration(\DateInterval $duration, $notify = TRUE) {
    // Generate an ISO 8601 formatted string as supported by
    // DateInterval::__construct() and setValue().
    $this->value = $duration->format('%rP%yY%mM%dDT%hH%mM%sS');
    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

}
