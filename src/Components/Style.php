<?php
namespace Matisse\Components;

use Matisse\Components\Base\Component;
use Matisse\Properties\Base\ComponentProperties;
use Matisse\Properties\TypeSystem\type;

class StyleProperties extends ComponentProperties
{
  /**
   * If set, allows inline stylesheet deduplication by ignoring Style instances with the same name as a previously run
   * Style.
   * > This only applies to inline styles, external stylesheets are always deduplicated.
   *
   * @var string
   */
  public $name = [type::id];
  /**
   * @var bool
   */
  public $prepend = false;
  /**
   * If set, the URL for an external CSS stylesheet.<br>
   * If not set, the tag content will be used as an inline stylesheet.
   *
   * @var string
   */
  public $src = '';
}

class Style extends Component
{
  const allowsChildren = true;

  const propertiesClass = StyleProperties::class;

  /** @var StyleProperties */
  public $props;

  /**
   * Registers a stylesheet on the Page.
   */
  protected function render ()
  {
    $prop = $this->props;
    if (exists ($prop->src))
      $this->context->getAssetsService ()->addStylesheet ($prop->src, $this->props->prepend);
    else if ($this->hasChildren ())
      $this->context->getAssetsService ()->addInlineCss (self::getRenderingOfSet ($this->getChildren ()), $prop->name,
        $this->props->prepend);
  }
}

