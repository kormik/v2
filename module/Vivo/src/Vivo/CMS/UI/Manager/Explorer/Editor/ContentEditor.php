<?php
namespace Vivo\CMS\UI\Manager\Explorer\Editor;

use Vivo\CMS\UI\AbstractForm;
use Vivo\CMS\UI\Manager\Form\ContentEditor as ContentEditorForm;

class ContentEditor extends AbstractForm
{
    /**
     * @var \Vivo\CMS\Model\ContentContainer
     */
    private $contentContainer;
    /**
     * @var array
     */
    private $contents = array();
    /**
     * @var \Vivo\CMS\Model\Entity
     */
    private $entity;
    /**
     * @var \Vivo\CMS\Api\Document
     */
    private $documentApi;
    /**
     * @var \Vivo\Metadata\MetadataManager
     */
    private $metadataManager;

    /**
     * @param \Vivo\CMS\Api\Document $documentApi
     * @param \Vivo\Metadata\MetadataManager $metadataManager
     * @param \Vivo\CMS\Model\ContentContainer $contentContainer
     */
    public function __construct(
        \Vivo\CMS\Api\Document $documentApi,
        \Vivo\Metadata\MetadataManager $metadataManager,
        \Vivo\CMS\Model\ContentContainer $contentContainer)
    {
        $this->documentApi = $documentApi;
        $this->metadataManager = $metadataManager;
        $this->contentContainer = $contentContainer;
        $this->autoAddCsrf = false;
    }

    public function init()
    {
        $this->contents = $this->documentApi->getContents($this->contentContainer);

        foreach ($this->contents as $content) {
//             echo $content->getUuid()." - " .$content->getPath()."\n";

            if($content->getState() == 'PUBLISHED') {
                $this->entity = $content;
                break;
            }
        }

        $this->getForm()->bind($this->entity);

        parent::init();
    }

    protected function doGetForm()
    {
        $metadata = $this->metadataManager->getMetadata(get_class($this->entity));

        $form = new ContentEditorForm('content-'.$this->entity->getUuid(), $this->contents, $metadata);

        return $form;
    }

    public function changeVersion()
    {
//         echo __METHOD__;
    }

    /**
     * @return boolean
     */
    public function save()
    {
        if ($this->getForm()->isValid()) {
            $this->documentApi->saveContent($this->entity);

            return true;
        }

        return false;
    }
}