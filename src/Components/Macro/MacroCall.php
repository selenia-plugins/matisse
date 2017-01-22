<?php

namespace Matisse\Components\Macro;

use Electro\ViewEngine\Lib\ViewModel;
use Matisse\Components\Base\Component;
use Matisse\Components\Base\CompositeComponent;
use Matisse\Components\Metadata;
use Matisse\Exceptions\ComponentException;
use Matisse\Properties\TypeSystem\type;

/**
 * A `MacroCall` is a component that can be represented via any tag that has the same name as the macro it refers to.
 */
class MacroCall extends CompositeComponent
{
  const TAG_NAME       = 'Call';
  const allowsChildren = true;

  function render ()
  {
    // Validate defaultParam's value.
    $def = $this->getDefaultParam ();
    if (!empty($def)) {
      if (!$this->props->defines ($def))
        throw new ComponentException($this,
          "Invalid value for <kbd>defaultParam</kbd> on <b>&lt;{$this->getTagName()}></b>; parameter <kbd>$def</kbd> does not exist");

      // Move children to default parameter.
      if ($this->hasChildren ()) {
        $type = $this->props->getTypeOf ($def);
        if ($type != type::content && $type != type::metadata)
          throw new ComponentException($this, sprintf (
            "The macro's default parameter <kbd>$def</kbd> can't hold content because its type is <kbd>%s</kbd>.",
            type::getNameOf ($type)));

        $param = new Metadata($this->context, ucfirst ($def), $type);
        $this->props->set ($def, $param);
        $param->attachTo ($this);
        $param->setChildren ($this->getChildren ());
      }
    }
    elseif ($this->hasChildren ())
      throw new ComponentException ($this,
        'You may not specify content for this tag because it has no default property');

    parent::render ();
  }

  function supportsProperties ()
  {
    return true;
  }

  protected function getDefaultParam ()
  {
    return $this->getMacro ()->props->defaultParam;
  }

//  protected function setupViewModel ()
//  {
//    parent::setupViewModel ();
//    foreach ($this->props->getPropertiesOf (type::content, type::metadata, type::collection) as $prop => $v)
//      $this->props->$prop->preRun();
//  }

  /**
   * @return Macro
   */
  protected function getMacro ()
  {
    /** @noinspection PhpIncompatibleReturnTypeInspection */
    return $this->getShadowDOM ()->getFirstChild ();
  }

  /**
   * Loads the macro with the name specified by the `macro` property.
   *
   * @param array|null $props
   * @param Component  $parent
   * @throws ComponentException
   * @throws \Matisse\Exceptions\MatisseException
   */
  protected function onCreate (array $props = null, Component $parent = null)
  {
//    $this->parent = $parent;
    $tagName      = $this->getTagName ();
    $propsClass   = $tagName . 'MacroProps';
    if (!class_exists ($propsClass, false)) {
      $this->context->getMacrosService ()->setupMacroProperties ($propsClass, $this->templateUrl,
        function () {
          $this->createView ();
          return $this->getMacro ();
        });
    }
    $this->props = new $propsClass ($this);
    parent::onCreate ($props, $parent);
  }

  protected function viewModel (ViewModel $viewModel)
  {
    parent::viewModel ($viewModel);
    // Import the container's model (if any) to the macro's view model
    $viewModel->model = $this->context->getDataBinder ()->getViewModel ()->model;
    $this->getMacro ()->importServices ($viewModel);
  }

}
