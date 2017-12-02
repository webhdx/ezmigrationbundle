<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use Kaliop\eZMigrationBundle\API\Collection\LocationCollection;
use Kaliop\eZMigrationBundle\API\Collection\TrashedItemCollection;
use Kaliop\eZMigrationBundle\Core\Matcher\TrashMatcher;
use Kaliop\eZMigrationBundle\Core\Helper\SortConverter;

/**
 * Handles trash migrations.
 */
class TrashManager extends RepositoryExecutor
{
    protected $supportedActions = array('purge', 'recover', 'delete');
    protected $supportedStepTypes = array('trash');

    /** @var TrashMatcher $trashMatcher */
    protected $trashMatcher;

    protected $sortConverter;

    /**
     * @param TrashMatcher $trashMatcher
     */
    public function __construct(TrashMatcher $trashMatcher, SortConverter $sortConverter)
    {
        $this->trashMatcher = $trashMatcher;
        $this->sortConverter = $sortConverter;
    }

    /**
     * Handles emptying the trash
     */
    protected function purge($step)
    {
        $trashService = $this->repository->getTrashService();

        $trashService->emptyTrash();

        return true;
    }

    /**
     * Handles the trash-restore migration action
     *
     * @todo support handling of restoration to custom locations
     */
    protected function recover($step)
    {
        $itemsCollection = $this->matchItems('restore', $step);

        if (count($itemsCollection) > 1 && array_key_exists('references', $step->dsl)) {
            throw new \Exception("Can not execute Trash restore because multiple types match, and a references section is specified in the dsl. References can be set when only 1 section matches");
        }

        $locations = array();
        $trashService = $this->repository->getTrashService();
        foreach ($itemsCollection as $key => $item) {
            $locations[] = $trashService->recover($item);
        }

        $this->setReferences(new LocationCollection($locations), $step);

        return $itemsCollection;
    }

    /**
     * Handles the trash-delete migration action
     */
    protected function delete($step)
    {
        $itemsCollection = $this->matchItems('delete', $step);

        if (count($itemsCollection) > 1 && array_key_exists('references', $step->dsl)) {
            throw new \Exception("Can not execute Trash restore because multiple types match, and a references section is specified in the dsl. References can be set when only 1 section matches");
        }

        $this->setReferences($itemsCollection, $step);

        $trashService = $this->repository->getTrashService();
        foreach ($itemsCollection as $key => $item) {
            $trashService->deleteTrashItem($item);
        }

        $this->setReferences($itemsCollection, $step);

        return $itemsCollection;
    }

    /**
     * @param string $action
     * @return TrashedItemCollection
     * @throws \Exception
     */
    protected function matchItems($action, $step)
    {
        if (!isset($step->dsl['match'])) {
            throw new \Exception("A match condition is required to $action trash items");
        }

        // convert the references passed in the match
        $match = $this->resolveReferencesRecursively($step->dsl['match']);

        return $this->trashMatcher->match($match);
    }

    /**
     * Sets references to certain trashed-item attributes.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\TrashItem|TrashedItemCollection|\eZ\Publish\API\Repository\Values\Content\Location|LocationCollection $item
     * @param $step
     * @throws \InvalidArgumentException When trying to set a reference to an unsupported attribute
     * @return boolean
     */
    protected function setReferences($item, $step)
    {
        if (!array_key_exists('references', $step->dsl)) {
            return false;
        }

        $references = $this->setReferencesCommon($item, $step->dsl['references']);
        $item = $this->insureSingleEntity($item, $references);

        foreach ($references as $reference) {
            switch ($reference['attribute']) {
                // a trashed item extends a location, so in theory everything 'location' here should work
                case 'location_id':
                case 'id':
                    $value = $item->id;
                    break;
                case 'remote_id':
                case 'location_remote_id':
                    $value = $item->remoteId;
                    break;
                case 'always_available':
                    $value = $item->contentInfo->alwaysAvailable;
                    break;
                case 'content_id':
                    $value = $item->contentId;
                    break;
                case 'content_type_id':
                    $value = $item->contentInfo->contentTypeId;
                    break;
                case 'content_type_identifier':
                    $contentTypeService = $this->repository->getContentTypeService();
                    $value = $contentTypeService->loadContentType($item->contentInfo->contentTypeId)->identifier;
                    break;
                case 'current_version':
                case 'current_version_no':
                    $value = $item->contentInfo->currentVersionNo;
                    break;
                case 'depth':
                    $value = $item->depth;
                    break;
                case 'is_hidden':
                    $value = $item->hidden;
                    break;
                case 'main_location_id':
                    $value = $item->contentInfo->mainLocationId;
                    break;
                case 'main_language_code':
                    $value = $item->contentInfo->mainLanguageCode;
                    break;
                case 'modification_date':
                    $value = $item->contentInfo->modificationDate->getTimestamp();
                    break;
                case 'name':
                    $value = $item->contentInfo->name;
                    break;
                case 'owner_id':
                    $value = $item->contentInfo->ownerId;
                    break;
                case 'parent_location_id':
                    $value = $item->parentLocationId;
                    break;
                case 'path':
                    $value = $item->pathString;
                    break;
                case 'priority':
                    $value = $item->priority;
                    break;
                case 'publication_date':
                    $value = $item->contentInfo->publishedDate->getTimestamp();
                    break;
                case 'section_id':
                    $value = $item->contentInfo->sectionId;
                    break;
                case 'section_identifier':
                    $sectionService = $this->repository->getSectionService();
                    $value = $sectionService->loadSection($item->contentInfo->sectionId)->identifier;
                    break;
                case 'sort_field':
                    $value = $this->sortConverter->sortField2Hash($item->sortField);
                    break;
                case 'sort_order':
                    $value = $this->sortConverter->sortOrder2Hash($item->sortOrder);
                    break;
                default:
                    throw new \InvalidArgumentException('Trash Manager does not support setting references for attribute ' . $reference['attribute']);
            }

            $overwrite = false;
            if (isset($reference['overwrite'])) {
                $overwrite = $reference['overwrite'];
            }
            $this->referenceResolver->addReference($reference['identifier'], $value, $overwrite);
        }

        return true;
    }
}