<?php

namespace Gedmo\Uploadable;

use Doctrine\Common\EventArgs;
use Doctrine\Common\NotifyPropertyChanged;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\Common\Persistence\ObjectManager;
use Gedmo\Exception\UploadableInvalidPathException;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\Mapping\ObjectManagerHelper as OMH;
use Gedmo\Exception\UploadablePartialException;
use Gedmo\Exception\UploadableCantWriteException;
use Gedmo\Exception\UploadableExtensionException;
use Gedmo\Exception\UploadableFormSizeException;
use Gedmo\Exception\UploadableIniSizeException;
use Gedmo\Exception\UploadableNoFileException;
use Gedmo\Exception\UploadableNoTmpDirException;
use Gedmo\Exception\UploadableUploadException;
use Gedmo\Exception\UploadableFileAlreadyExistsException;
use Gedmo\Exception\UploadableNoPathDefinedException;
use Gedmo\Exception\UploadableMaxSizeException;
use Gedmo\Exception\UploadableInvalidMimeTypeException;
use Gedmo\Exception\UploadableCouldntGuessMimeTypeException;
use Gedmo\Uploadable\FileInfo\FileInfoInterface;
use Gedmo\Uploadable\MimeType\MimeTypeGuesser;
use Gedmo\Uploadable\MimeType\MimeTypeGuesserInterface;
use Gedmo\Uploadable\Event\UploadablePreFileProcessEventArgs;
use Gedmo\Uploadable\Event\UploadablePostFileProcessEventArgs;
use Gedmo\Uploadable\Mapping\UploadableMetadata;

/**
 * Uploadable listener
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class UploadableListener extends MappedEventSubscriber
{
    const ACTION_INSERT = 'INSERT';
    const ACTION_UPDATE = 'UPDATE';

    /**
     * Default path to move files in
     *
     * @var string
     */
    private $defaultPath;

    /**
     * Mime type guesser
     *
     * @var \Gedmo\Uploadable\MimeType\MimeTypeGuesserInterface
     */
    private $mimeTypeGuesser;

    /**
     * Default FileInfoInterface class
     *
     * @var string
     */
    private $defaultFileInfoClass = 'Gedmo\Uploadable\FileInfo\FileInfoArray';

    /**
     * Array of files to remove on postFlush
     *
     * @var array
     */
    private $pendingFileRemovals = array();

    /**
     * Array of FileInfoInterface objects. The index is the hash of the entity owner
     * of the FileInfoInterface object.
     *
     * @var array
     */
    private $fileInfoObjects = array();

    public function __construct(MimeTypeGuesserInterface $mimeTypeGuesser = null)
    {
        $this->mimeTypeGuesser = $mimeTypeGuesser ? $mimeTypeGuesser : new MimeTypeGuesser();
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            'loadClassMetadata',
            'preFlush',
            'onFlush',
            'postFlush',
        );
    }

    /**
     * This event is needed in special cases where the entity needs to be updated, but it only has the
     * file field modified. Since we can't mark an entity as "dirty" in the "addEntityFileInfo" method,
     * doctrine thinks the entity has no changes, which produces that the "onFlush" event gets never called.
     * Here we mark the entity as dirty, so the "onFlush" event gets called, and the file is processed.
     *
     * @param \Doctrine\Common\EventArgs $event
     */
    public function preFlush(EventArgs $event)
    {
        if (empty($this->fileInfoObjects)) {
            // Nothing to do
            return;
        }

        $om = OMH::getObjectManagerFromEvent($event);
        $uow = $om->getUnitOfWork();
        $first = reset($this->fileInfoObjects);
        $meta = $om->getClassMetadata(get_class($first['entity']));
        $exm = $this->getConfiguration($om, $meta->name);

        foreach ($this->fileInfoObjects as $info) {
            $entity = $info['entity'];

            // If the entity is in the identity map, it means it will be updated. We need to force the
            // "dirty check" here by "modifying" the path. We are actually setting the same value, but
            // this will mark the entity as dirty, and the "onFlush" event will be fired, even if there's
            // no other change in the entity's fields apart from the file itself.
            if ($uow->isInIdentityMap($entity)) {
                if ($exm->getOption('filePathField')) {
                    $path = $this->getFilePath($meta, $exm->getOptions(), $entity);

                    $uow->propertyChanged($entity, $exm->getOption('filePathField'), $path, $path);
                } else {
                    $fileName = $this->getFileName($meta, $exm->getOptions(), $entity);

                    $uow->propertyChanged($entity, $exm->getOption('fileNameField'), $fileName, $fileName);
                }

                $uow->scheduleForUpdate($entity);
            }
        }
    }

    /**
     * Handle file-uploading depending on the action
     * being done with objects
     *
     * @param \Doctrine\Common\EventArgs $event
     */
    public function onFlush(EventArgs $event)
    {
        $om = OMH::getObjectManagerFromEvent($event);
        $uow = $om->getUnitOfWork();

        // Do we need to upload files?
        foreach ($this->fileInfoObjects as $info) {
            $entity = $info['entity'];
            $scheduledForInsert = $uow->isScheduledForInsert($entity);
            $scheduledForUpdate = $uow->isScheduledForUpdate($entity);
            $action = ($scheduledForInsert || $scheduledForUpdate) ?
                ($scheduledForInsert ? self::ACTION_INSERT : self::ACTION_UPDATE) :
                false;

            if ($action) {
                $this->processFile($om, $entity, $action);
            }
        }

        // Do we need to remove any files?
        foreach (OMH::getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));

            if ($exm = $this->getConfiguration($om, $meta->name)) {
                if ($exm->getOption('filePathField')) {
                    $this->pendingFileRemovals[] = $this->getFilePath($meta, $exm->getOptions(), $object);
                } else {
                    $path     = $this->getPath($meta, $exm->getOptions(), $object);
                    $fileName = $this->getFileName($meta, $exm->getOptions(), $object);
                    $this->pendingFileRemovals[] = $path.DIRECTORY_SEPARATOR.$fileName;
                }
            }
        }
    }

    /**
     * Handle removal of files
     *
     * @param \Doctrine\Common\EventArgs $event
     */
    public function postFlush(EventArgs $event)
    {
        if (!empty($this->pendingFileRemovals)) {
            foreach ($this->pendingFileRemovals as $file) {
                $this->removeFile($file);
            }

            $this->pendingFileRemovals = array();
        }

        $this->fileInfoObjects = array();
    }

    /**
     * If it's a Uploadable object, verify if the file was uploaded.
     * If that's the case, process it.
     *
     * @param ObjectManager $om
     * @param object        $object
     * @param string        $action
     *
     * @throws \Gedmo\Exception\UploadableNoPathDefinedException
     * @throws \Gedmo\Exception\UploadableCouldntGuessMimeTypeException
     * @throws \Gedmo\Exception\UploadableMaxSizeException
     * @throws \Gedmo\Exception\UploadableInvalidMimeTypeException
     */
    public function processFile(ObjectManager $om, $object, $action)
    {
        $oid = spl_object_hash($object);
        $uow = $om->getUnitOfWork();
        $meta = $om->getClassMetadata(get_class($object));
        if (!$exm = $this->getConfiguration($om, $meta->name)) {
            return;
        }

        $refl = $meta->getReflectionClass();
        $fileInfo = $this->fileInfoObjects[$oid]['fileInfo'];
        $evm = $om->getEventManager();
        $options = $exm->getOptions();

        if ($evm->hasListeners(Events::uploadablePreFileProcess)) {
            $evm->dispatchEvent(Events::uploadablePreFileProcess, new UploadablePreFileProcessEventArgs(
                $this,
                $om,
                $options,
                $fileInfo,
                $object,
                $action
            ));
        }

        // Validations
        if ($options['maxSize'] > 0 && $fileInfo->getSize() > $options['maxSize']) {
            $msg = 'File "%s" exceeds the maximum allowed size of %d bytes. File size: %d bytes';

            throw new UploadableMaxSizeException(sprintf($msg,
                $fileInfo->getName(),
                $options['maxSize'],
                $fileInfo->getSize()
            ));
        }

        $mime = $this->mimeTypeGuesser->guess($fileInfo->getTmpName());

        if (!$mime) {
            throw new UploadableCouldntGuessMimeTypeException(sprintf('Couldn\'t guess mime type for file "%s".',
                $fileInfo->getName()
            ));
        }

        if ($options['allowedTypes'] && !in_array($mime, $options['allowedTypes'])) {
            throw new UploadableInvalidMimeTypeException(sprintf('Invalid mime type "%s" for file "%s", allowed are: %s.',
                $mime,
                $fileInfo->getName(),
                implode(', ', $options['allowedTypes'])
            ));
        }

        if ($options['disallowedTypes'] && in_array($mime, $options['disallowedTypes'])) {
            throw new UploadableInvalidMimeTypeException(sprintf('Invalid mime type "%s" for file "%s", restricted are: %s.',
                $mime,
                $fileInfo->getName(),
                implode(', ', $options['disallowedTypes'])
            ));
        }

        $path = $this->getPath($meta, $options, $object);

        if ($action === self::ACTION_UPDATE) {
            // First we add the original file to the pendingFileRemovals array
            if ($options['filePathField']) {
                $this->pendingFileRemovals[] = $this->getFilePath($meta, $options, $object);
            } else {
                $path     = $this->getPath($meta, $options, $object);
                $fileName = $this->getFileName($meta, $options, $object);
                $this->pendingFileRemovals[] = $path.DIRECTORY_SEPARATOR.$fileName;
            }
        }

        // We generate the filename based on configuration
        $generatorNamespace = 'Gedmo\Uploadable\FilenameGenerator';

        switch ($options['filenameGenerator']) {
            case UploadableMetadata::GENERATOR_ALPHANUMERIC:
                $generatorClass = $generatorNamespace.'\FilenameGeneratorAlphanumeric';

                break;
            case UploadableMetadata::GENERATOR_SHA1:
                $generatorClass = $generatorNamespace.'\FilenameGeneratorSha1';

                break;
            case UploadableMetadata::GENERATOR_NONE:
                $generatorClass = false;

                break;
            default:
                $generatorClass = $options['filenameGenerator'];
        }

        $info = $this->moveFile($fileInfo, $path, $generatorClass, $options['allowOverwrite'], $options['appendNumber'], $object);

        // We override the mime type with the guessed one
        $info['fileMimeType'] = $mime;

        if ($options['callback'] !== '') {
            $callbackMethod = $refl->getMethod($options['callback']);
            $callbackMethod->setAccessible(true);
            $callbackMethod->invokeArgs($object, array($info));
        }

        if ($options['filePathField']) {
            $this->updateField($om, $object, $meta, $options['filePathField'], $info['filePath']);
        }

        if ($options['fileNameField']) {
            $this->updateField($om, $object, $meta, $options['fileNameField'], $info['fileName']);
        }

        if ($options['fileMimeTypeField']) {
            $this->updateField($om, $object, $options['fileMimeTypeField'], $info['fileMimeType']);
        }

        if ($options['fileSizeField']) {
            $this->updateField($om, $object, $options['fileSizeField'], $info['fileSize']);
        }

        OMH::recomputeSingleObjectChangeSet($uow, $meta, $object);

        if ($evm->hasListeners(Events::uploadablePostFileProcess)) {
            $evm->dispatchEvent(Events::uploadablePostFileProcess, new UploadablePostFileProcessEventArgs(
                $this,
                $om,
                $options,
                $fileInfo,
                $object,
                $action
            ));
        }

        unset($this->fileInfoObjects[$oid]);
    }

    /**
     * @param ClassMetadata $meta
     * @param array         $options
     * @param object        $object  Entity
     *
     * @return string
     *
     * @throws UploadableNoPathDefinedException
     */
    protected function getPath(ClassMetadata $meta, array $options, $object)
    {
        $path = $options['path'];

        if ($path === '') {
            $defaultPath = $this->getDefaultPath();
            if ($options['pathMethod'] !== '') {
                $pathMethod = $meta->getReflectionClass()->getMethod($options['pathMethod']);
                $pathMethod->setAccessible(true);
                $path = $pathMethod->invoke($object, $defaultPath);
            } elseif ($defaultPath !== null) {
                $path = $defaultPath;
            } else {
                $msg = 'You have to define the path to save files either in the listener, or in the class "%s"';

                throw new UploadableNoPathDefinedException(
                    sprintf($msg, $meta->name)
                );
            }
        }

        if (!is_string($path) || $path === '') {
            throw new UploadableInvalidPathException("Path must be a string containing the path to a valid directory. {$path} was given.");
        }

        if (!is_dir($path) && !@mkdir($path, 0777, true)) {
            throw new UploadableInvalidPathException(sprintf('Unable to create "%s" directory.', $path));
        }

        if (!is_writable($path)) {
            throw new UploadableCantWriteException(sprintf('Directory "%s" is not writable.', $path));
        }
        $path = rtrim($path, '\/');

        return $path;
    }

    /**
     * Returns value of the entity's property
     *
     * @param ClassMetadata $meta
     * @param string        $propertyName
     * @param object        $object
     *
     * @return mixed
     */
    protected function getPropertyValueFromObject(ClassMetadata $meta, $propertyName, $object)
    {
        $refl = $meta->getReflectionClass();
        $field = $refl->getProperty($propertyName);
        $field->setAccessible(true);

        return $field->getValue($object);
    }

    /**
     * Returns the stored path of the entity's file
     *
     * @param ClassMetadata $meta
     * @param array         $options
     * @param object        $object
     *
     * @return string
     */
    protected function getFilePath(ClassMetadata $meta, array $options, $object)
    {
        return $this->getPropertyValueFromObject($meta, $options['filePathField'], $object);
    }

    /**
     * Returns the name of the entity's file
     *
     * @param ClassMetadata $meta
     * @param array         $options
     * @param object        $object
     *
     * @return string
     */
    protected function getFileName(ClassMetadata $meta, array $options, $object)
    {
        return $this->getPropertyValueFromObject($meta, $options['fileNameField'], $object);
    }

    /**
     * Simple wrapper for the function "unlink" to ease testing
     *
     * @param string $filePath
     *
     * @return bool
     */
    public function removeFile($filePath)
    {
        if (is_file($filePath)) {
            return @unlink($filePath);
        }

        return false;
    }

    /**
     * Moves the file to the specified path
     *
     * @param FileInfoInterface $fileInfo
     * @param string            $path
     * @param bool              $filenameGeneratorClass
     * @param bool              $overwrite
     * @param bool              $appendNumber
     * @param object            $object
     *
     * @return array
     *
     * @throws \Gedmo\Exception\UploadableUploadException
     * @throws \Gedmo\Exception\UploadableNoFileException
     * @throws \Gedmo\Exception\UploadableExtensionException
     * @throws \Gedmo\Exception\UploadableIniSizeException
     * @throws \Gedmo\Exception\UploadableFormSizeException
     * @throws \Gedmo\Exception\UploadableFileAlreadyExistsException
     * @throws \Gedmo\Exception\UploadablePartialException
     * @throws \Gedmo\Exception\UploadableNoTmpDirException
     * @throws \Gedmo\Exception\UploadableCantWriteException
     */
    public function moveFile(FileInfoInterface $fileInfo, $path, $filenameGeneratorClass = false, $overwrite = false, $appendNumber = false, $object)
    {
        if ($fileInfo->getError() > 0) {
            switch ($fileInfo->getError()) {
                case 1:
                    $msg = 'Size of uploaded file "%s" exceeds limit imposed by directive "upload_max_filesize" in php.ini';

                    throw new UploadableIniSizeException(sprintf($msg, $fileInfo->getName()));
                case 2:
                    $msg = 'Size of uploaded file "%s" exceeds limit imposed by option MAX_FILE_SIZE in your form.';

                    throw new UploadableFormSizeException(sprintf($msg, $fileInfo->getName()));
                case 3:
                    $msg = 'File "%s" was partially uploaded.';

                    throw new UploadablePartialException(sprintf($msg, $fileInfo->getName()));
                case 4:
                    $msg = 'No file was uploaded!';

                    throw new UploadableNoFileException(sprintf($msg, $fileInfo->getName()));
                case 6:
                    $msg = 'Upload failed. Temp dir is missing.';

                    throw new UploadableNoTmpDirException($msg);
                case 7:
                    $msg = 'File "%s" couldn\'t be uploaded because directory is not writable.';

                    throw new UploadableCantWriteException(sprintf($msg, $fileInfo->getName()));
                case 8:
                    $msg = 'A PHP Extension stopped the uploaded for some reason.';

                    throw new UploadableExtensionException(sprintf($msg, $fileInfo->getName()));
                default:
                    throw new UploadableUploadException(sprintf('There was an unknown problem while uploading file "%s"',
                        $fileInfo->getName()
                    ));
            }
        }

        $info = array(
            'fileName'          => basename($fileInfo->getName()),
            'fileExtension'     => '',
            'fileWithoutExt'    => '',
            'origFileName'      => '',
            'filePath'          => '',
            'fileMimeType'      => $fileInfo->getType(),
            'fileSize'          => $fileInfo->getSize(),
        );

        $info['filePath'] = $path.'/'.$info['fileName'];
        $info['fileWithoutExt'] = pathinfo($info['fileName'], PATHINFO_FILENAME);
        $info['fileExtension'] = pathinfo($info['fileName'], PATHINFO_EXTENSION);

        // Save the original filename for later use
        $info['origFileName'] = $info['fileName'];

        // Now we generate the filename using the configured class
        if ($filenameGeneratorClass) {
            $info['fileName'] = $filenameGeneratorClass::generate(
                $info['fileWithoutExt'],
                $info['fileExtension'],
                $object
            );

            $info['filePath'] = $path.'/'.$info['fileName'];
            $info['fileWithoutExt'] = pathinfo($info['fileName'], PATHINFO_FILENAME);
        }

        if (is_file($info['filePath'])) {
            if ($overwrite) {
                $this->removeFile($info['filePath']);
            } elseif ($appendNumber) {
                $counter = 1;
                $info['filePath'] = $path.'/'.$info['fileWithoutExt'].'-'.$counter.'.'.$info['fileExtension'];

                do {
                    $info['filePath'] = $path.'/'.$info['fileWithoutExt'].'-'.(++$counter).'.'.$info['fileExtension'];
                } while (is_file($info['filePath']));
            } else {
                throw new UploadableFileAlreadyExistsException(sprintf('File "%s" already exists!',
                    $info['filePath']
                ));
            }
        }

        if (!$this->doMoveFile($fileInfo->getTmpName(), $info['filePath'], $fileInfo->isUploadedFile())) {
            throw new UploadableUploadException(sprintf('File "%s" was not uploaded, or there was a problem moving it to the location "%s".',
                $fileInfo->getName(),
                $path
            ));
        }

        return $info;
    }

    /**
     * Simple wrapper method used to move the file. If it's an uploaded file
     * it will use the "move_uploaded_file method. If it's not, it will
     * simple move it
     *
     * @param string $source         Source file
     * @param string $dest           Destination file
     * @param bool   $isUploadedFile Whether this is an uploaded file?
     *
     * @return bool
     */
    public function doMoveFile($source, $dest, $isUploadedFile = true)
    {
        return $isUploadedFile ? @move_uploaded_file($source, $dest) : @copy($source, $dest);
    }

    /**
     * Maps additional metadata
     *
     * @param EventArgs $event
     */
    public function loadClassMetadata(EventArgs $event)
    {
        $this->loadMetadataForObjectClass(OMH::getObjectManagerFromEvent($event), $event->getClassMetadata());
    }

    /**
     * Sets the default path
     *
     * @param string $path
     *
     * @return void
     */
    public function setDefaultPath($path)
    {
        $this->defaultPath = $path;
    }

    /**
     * Returns default path
     *
     * @return string
     */
    public function getDefaultPath()
    {
        return $this->defaultPath;
    }

    /**
     * Sets file info default class
     *
     * @param string $defaultFileInfoClass
     *
     * @return void
     */
    public function setDefaultFileInfoClass($defaultFileInfoClass)
    {
        $fileInfoInterface = 'Gedmo\\Uploadable\\FileInfo\\FileInfoInterface';
        $refl = is_string($defaultFileInfoClass) && class_exists($defaultFileInfoClass) ?
            new \ReflectionClass($defaultFileInfoClass) :
            false;

        if (!$refl || !$refl->implementsInterface($fileInfoInterface)) {
            $msg = sprintf('Default FileInfo class must be a valid class, and it must implement "%s".',
                $fileInfoInterface
            );

            throw new \Gedmo\Exception\InvalidArgumentException($msg);
        }

        $this->defaultFileInfoClass = $defaultFileInfoClass;
    }

    /**
     * Returns file info default class
     *
     * @return string
     */
    public function getDefaultFileInfoClass()
    {
        return $this->defaultFileInfoClass;
    }

    /**
     * Adds a FileInfoInterface object for the given entity
     *
     * @param object                  $entity
     * @param array|FileInfoInterface $fileInfo
     *
     * @throws \RuntimeException
     */
    public function addEntityFileInfo($entity, $fileInfo)
    {
        $fileInfoClass = $this->getDefaultFileInfoClass();
        $fileInfo = is_array($fileInfo) ? new $fileInfoClass($fileInfo) : $fileInfo;

        if (!$fileInfo instanceof FileInfoInterface) {
            $msg = 'You must pass an instance of FileInfoInterface or a valid array for entity of class "%s".';

            throw new \RuntimeException(sprintf($msg, get_class($entity)));
        }

        $this->fileInfoObjects[spl_object_hash($entity)] = array(
            'entity'        => $entity,
            'fileInfo'      => $fileInfo,
        );
    }

    /**
     * @param object $entity
     *
     * @return FileInfoInterface
     */
    public function getEntityFileInfo($entity)
    {
        $oid = spl_object_hash($entity);

        if (!isset($this->fileInfoObjects[$oid])) {
            throw new \RuntimeException(sprintf('There\'s no FileInfoInterface object for entity of class "%s".',
                get_class($entity)
            ));
        }

        return $this->fileInfoObjects[$oid]['fileInfo'];
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }

    /**
     * @param \Gedmo\Uploadable\MimeType\MimeTypeGuesserInterface $mimeTypeGuesser
     */
    public function setMimeTypeGuesser(MimeTypeGuesserInterface $mimeTypeGuesser)
    {
        $this->mimeTypeGuesser = $mimeTypeGuesser;
    }

    /**
     * @return \Gedmo\Uploadable\MimeType\MimeTypeGuesserInterface
     */
    public function getMimeTypeGuesser()
    {
        return $this->mimeTypeGuesser;
    }

    /**
     * @param ObjectManager $om
     * @param object        $object
     * @param string        $field
     * @param mixed         $value
     * @param bool          $notifyPropertyChanged
     */
    protected function updateField(ObjectManager $om, $object, $field, $value, $notifyPropertyChanged = true)
    {
        $meta = $om->getClassMetadata(get_class($object));
        $property = $meta->getReflectionProperty($field);
        $oldValue = $property->getValue($object);
        $property->setValue($object, $value);

        if ($notifyPropertyChanged && $object instanceof NotifyPropertyChanged) {
            $uow = $om->getUnitOfWork();
            $uow->propertyChanged($object, $field, $oldValue, $value);
        }
    }
}
