<?php

namespace Matisse\Lib;

use Electro\Caching\Lib\CachingFileCompiler;
use Electro\Interfaces\DI\InjectorInterface;
use Electro\Interfaces\Views\ViewEngineInterface;
use Electro\Interfaces\Views\ViewModelInterface;
use Electro\Interfaces\Views\ViewServiceInterface;
use Matisse\Components\Base\CompositeComponent;
use Matisse\Components\Base\PageComponent;
use Matisse\Components\DocumentFragment;
use Matisse\Exceptions\MatisseException;
use Matisse\Parser\DocumentContext;
use Matisse\Parser\Parser;

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
   * When set, loading the template will generate a root component of the specified class.
   * <p>When NULL, {@see DocumentFragment} is returned.
   *
   * @var string|null
   */
  private $rootClass = null;
  /**
   * @var ViewServiceInterface
   */
  private $view;

  function __construct (ViewServiceInterface $view, DocumentContext $context, InjectorInterface $injector)
  {
    $this->view     = $view; // The view is always the owner if this engine, as long as the parameter is called $view
    $this->context  = $context;
    $this->injector = $injector;
  }

  function compile ($src)
  {
    if (!$this->context)
      throw new MatisseException ("No rendering context is set");

    // Create a compiled template.

    $root = new DocumentFragment;
    $root->setContext ($context = $this->context->makeSubcontext ());

    $parser = new Parser;
    $parser->parse ($src, $root);

    return $root;
  }

  function configure (array $options = [])
  {
    $this->rootClass = get ($options, 'rootClass')
      ?: (get ($options, 'page') ? PageComponent::class : null);
  }

  function loadFromCache (CachingFileCompiler $cache, $sourceFile)
  {
    global $usrlz_ctx, $usrlz_inj;

    // Preserve the current context.
    $prev_ctx = $usrlz_ctx;

    $usrlz_ctx = $this->context->makeSubcontext ();
    $usrlz_inj = $this->injector;

    $compiled = $cache->get ($sourceFile, function ($source) use ($sourceFile) {
      $root = $this->compile ($source);
      if ($root instanceof CompositeComponent)
        $root->templateUrl = $sourceFile;
      return $root;
    }, str_match ($sourceFile, '#^.*?[\\\\/](.*)\.\w+$#')[1]);

    // Restore the current context.
    $usrlz_ctx = $prev_ctx;

    if ($this->rootClass) {
      /** @var CompositeComponent $root */
      $root = $this->injector->make ($this->rootClass);
      $root->setContext ($this->context->makeSubcontext ());
      $root->setShadowDOM ($compiled);
      return $root;
    }

    return $compiled;
  }

  function render ($compiled, ViewModelInterface $data = null)
  {
    if ($data) {
      $c = $compiled instanceof CompositeComponent ? $compiled->getShadowDom () : $compiled;
      $c->getDataBinder ()->setViewModel ($data);
    }

    /** @var DocumentFragment $compiled */
    return (string)$compiled;
  }

}
