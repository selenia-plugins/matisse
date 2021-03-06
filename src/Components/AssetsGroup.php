<?php
namespace Matisse\Components;

use Matisse\Components\Base\Component;
use Matisse\Properties\Base\ComponentProperties;

class AssetsGroupProperties extends ComponentProperties
{
  /**
   * @var bool
   */
  public $prepend = false;
}

class AssetsGroup extends Component
{
  const allowsChildren = true;

  const propertiesClass = AssetsGroupProperties::class;

  /** @var AssetsGroupProperties */
  public $props;

  /**
   * Groups Script components under the same assets context.
   */
  protected function render ()
  {
    $this->context->getAssetsService ()->beginAssetsContext ($this->props->prepend);
    $this->runChildren ();
    $this->context->getAssetsService ()->endAssetsContext ();
  }

}

