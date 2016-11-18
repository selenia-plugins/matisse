<?php
namespace Electro\Plugins\Matisse\Lib;

use Electro\Interfaces\DI\InjectorInterface;
use Electro\Interfaces\Views\ViewEngineInterface;
use Electro\Interfaces\Views\ViewServiceInterface;
use Electro\Plugins\Matisse\Components\Internal\DocumentFragment;
use Electro\Plugins\Matisse\Exceptions\MatisseException;
use Electro\Plugins\Matisse\Parser\DocumentContext;
use Electro\Plugins\Matisse\Parser\Parser;

class MatisseEngine implements ViewEngineInterface
{
  /**
   * The current rendering context.
   *
   * @var DocumentContext
   */
  private $context;
  /**
   * @var InjectorInterface
   */
  private $injector;
  /**
   * @var ViewServiceInterface
   */
  private $view;

  function __construct (ViewServiceInterface $view, DocumentContext $context, InjectorInterface $injector)
  {
    $this->view    = $view; // The view is always the owner if this engine, as long as the parameter is called $view
    $this->context = clone $context;
    $this->injector = $injector;
  }

  function compile ($src)
  {
    if (!$this->context)
      throw new MatisseException ("No rendering context is set");

    // Create a compiled template.

    $root = new DocumentFragment;
    $root->setContext ($this->context->makeSubcontext ());

    $parser = new Parser;
    $parser->parse ($src, $root);
    return $root;

//    echo "<div style='white-space:pre-wrap'>";
//    echo serialize ($root);exit;

    $ser = serialize ($root);
    global $usrlz_ctx, $usrlz_inj;
    $usrlz_ctx = $root->context;
    $usrlz_inj = $this->injector;
    $root = unserialize($ser);
    return $root;
  }

  function configure ($options)
  {
//    if (!$options instanceof Context)
//      throw new \InvalidArgumentException ("The argument must be an instance of " . formatClassName (Context::class));
//    $this->context = $options;
  }

  function render ($compiled, $data = null)
  {
    // Matisse ignores the $data argument. The view model should be set by the CompositeComponent that owns the view,
    // and it is already set on the document context.

    /** @var DocumentFragment $compiled */
    return $compiled->getRendering ();
  }

}
