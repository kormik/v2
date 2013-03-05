<?php
namespace Vivo\Backend\UI\Form;

use Vivo\Backend\UI\Form\Fieldset\EntityEditor as EntityEditorFieldset;
use Vivo\Form\Form;
use Zend\Stdlib\Hydrator\ClassMethods as ClassMethodsHydrator;

/**
 * EntityEditor form.
 */
class EntityEditor extends Form
{
    /**
     * Constructor.
     *
     * @param string $name Form and fieldset name.
     * @param array $lookupData
     */
    public function __construct($name, array $lookupData)
    {
        parent::__construct($name);

        $this->setAttribute('method', 'post');

        // Fieldset
        $fieldset = new EntityEditorFieldset($name, $lookupData);
        $fieldset->setHydrator(new ClassMethodsHydrator(false));
        $fieldset->setOptions(array('use_as_base_fieldset' => true));

        $this->add($fieldset);
    }

}
