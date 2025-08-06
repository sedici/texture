<?php

/**
 * @file plugins/generic/texture/TextureHandler.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class TextureHandler
 * @ingroup plugins_generic_texture
 *
 * @brief Handle requests for Texture plugin
 */
namespace APP\plugins\generic\texture;

use PKP\db\DAORegistry;
use APP\core\Services;
use PKP\file\SubmissionFileManager; 
use PKP\file\FileManager;
use PKP\notification\PKPNotification;
use PKP\facades\Locale;
use PKP\security\Role;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\security\authorization\WorkflowStageAccessPolicy;
use APP\handler\Handler;
use APP\notification\NotificationManager;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use APP\plugins\generic\texture\classes\DAR;


class TextureHandler extends Handler {
	/** @var Submission * */
	public $submission;
	/** @var Publication * */
	public $publication;
	/** @var MarkupPlugin The Texture plugin */
	protected $_plugin;

	/**
	 * Constructor
	 */
	function __construct() {

		parent::__construct();

		$this->_plugin = PluginRegistry::getPlugin('generic', TEXTURE_PLUGIN_NAME);
		$this->addRoleAssignment(
			array(Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_AUTHOR),
			array('editor', 'export', 'json', 'extract', 'media', 'createGalleyForm', 'createGalley')
		);
	}

	//
	// Overridden methods from Handler
	//
	/**
	 * @copydoc PKPHandler::initialize()
	 */
	function initialize($request) {
		error_log('TextureHandler::initialize called');
		parent::initialize($request);
		$this->submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
		$this->publication = $this->submission->getLatestPublication();
		$this->setupTemplate($request);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new WorkflowStageAccessPolicy($request, $args, $roleAssignments, 'submissionId', (int)$request->getUserVar('stageId')));
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Create galley form
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage JSON object
	 */
	public function createGalleyForm($args, $request) {

		error_log('TextureHandler::createGalleyForm called');
		import('plugins.generic.texture.controllers.grid.form.TextureArticleGalleyForm');
		$galleyForm = new TextureArticleGalleyForm($request, $this->getPlugin(), $this->publication, $this->submission);

		$galleyForm->initData();
		return new JSONMessage(true, $galleyForm->fetch($request));
	}

	/**
	 * Get the plugin.
	 * @return TexuturePlugin
	 */
	function getPlugin() {

		return $this->_plugin;
	}

	/**
	 * Extracts a DAR Archive
	 * @param $args
	 * @param $request
	 */
	public function extract($args, $request) {
		error_log('TextureHandler::extract called');
		
		$user = $request->getUser();
		$zipType = $request->getUserVar("zipType");
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		$archivePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'texture-' . $zipType . '-archive' . mt_rand();
		$image_types = array('gif', 'jpg', 'jpeg', 'png', 'jpe');
		$html_types = array('html');

		$zip = new ZipArchive;
		if ($zip->open($submissionFile->getData('path')) === TRUE) {
			error_log("Entrando en EXTRACT existe ZIP");
			$submissionDao = Application::getSubmissionDAO();
			$submissionId = (int)$request->getUserVar('submissionId');
			$submission = $submissionDao->getById($submissionId);
			mkdir($archivePath, 0777, true);
			$zip->extractTo($archivePath);
			$genreDAO = DAORegistry::getDAO('GenreDAO');
			$genre = $genreDAO->getByKey('SUBMISSION', $submission->getData('contextId'));
			$fileStage = $submissionFile->getFileStage();
			$sourceFileId = $submissionFile->getData('submissionFileId');
			if ($zipType == TEXTURE_DAR_FILE_TYPE) {
				$manifestFileDom = new DOMDocument();
				$darManifestFilePath = $archivePath . DIRECTORY_SEPARATOR . DAR_MANIFEST_FILE;
				if (file_exists($darManifestFilePath)) {

					$manifestFileDom->load($darManifestFilePath);
					$documentNodeList = $manifestFileDom->getElementsByTagName("document");
					if ($documentNodeList->length == 1) {

						$darManuscriptFilePath = $archivePath . DIRECTORY_SEPARATOR . $documentNodeList[0]->getAttribute('path');

						if (file_exists($darManuscriptFilePath)) {

							$fileType = "text/xml";

							$clientFileName = basename($submissionFile->getClientFileName(), TEXTURE_DAR_FILE_TYPE) . 'xml';
							$fileSize = filesize($darManuscriptFilePath);

							$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
							$newSubmissionFile = $submissionFileDao->newDataObjectByGenreId($genre->getId());
							$newSubmissionFile->setSubmissionId($submission->getId());
							$newSubmissionFile->setSubmissionLocale($submission->getLocale());
							$newSubmissionFile->setGenreId($genre->getId());
							$newSubmissionFile->setFileStage($fileStage);
							$newSubmissionFile->setDateUploaded(Core::getCurrentDate());
							$newSubmissionFile->setDateModified(Core::getCurrentDate());
							$newSubmissionFile->setOriginalFileName($clientFileName);
							$newSubmissionFile->setUploaderUserId($user->getId());
							$newSubmissionFile->setFileSize($fileSize);
							$newSubmissionFile->setFileType($fileType);

							$newSubmissionFile->setSourceRevision($submissionFile->getRevision());
							$newSubmissionFile->setSourceFileId($sourceFileId);

							$insertedSubmissionFile = $submissionFileDao->insertObject($newSubmissionFile, $darManuscriptFilePath);

							$dependentFiles = $manifestFileDom->getElementsByTagName("asset");
							foreach ($dependentFiles as $asset) {

								$fileName = $asset->getAttribute('path');
								$dependentFilePath = $archivePath . DIRECTORY_SEPARATOR . $fileName;
								$fileType = pathinfo($fileName, PATHINFO_EXTENSION);

								$genreId = $this->_getGenreId($request, $fileType);
								$this->_createDependentFile($genreId, $submission, $fileName, SUBMISSION_FILE_DEPENDENT, ASSOC_TYPE_SUBMISSION_FILE, true, $insertedSubmissionFile->getFileId(), $dependentFilePath, $request);
							}
						} else {
							return $this->removeFilesAndNotify($zip, $archivePath, $user, __('plugins.generic.texture.notification.noManuscript'));
						}
					}

				} else {
					return $this->removeFilesAndNotify($zip, $archivePath, $user, __('plugins.generic.texture.notification.noManifest'));
				}
			} elseif ($zipType == TEXTURE_ZIP_FILE_TYPE) {

				$archiveContent = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archivePath), RecursiveIteratorIterator::SELF_FIRST);

				$productionFiles = [];
				$dependentFiles = [];

				{
					foreach ($archiveContent as $fileName => $fileObject) {

						if (in_array(pathinfo($fileName, PATHINFO_EXTENSION), $image_types)) {
							array_push($dependentFiles, $fileObject);
						}
						if (in_array(pathinfo($fileName, PATHINFO_EXTENSION), $html_types)) {
							array_push($productionFiles, $fileObject);
						}

					}
					if (count($productionFiles) == 1) {

						$htmlFile = $productionFiles[0];
						$fileType = "text/html";

						$clientFileName = basename($submissionFile->getClientFileName(), TEXTURE_ZIP_FILE_TYPE) . 'html';
						$fileSize = $htmlFile->getSize();
						$filePath = $htmlFile->getPathname();

						$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
						$newSubmissionFile = $submissionFileDao->newDataObjectByGenreId($genreId);
						$newSubmissionFile->setSubmissionId($submission->getId());
						$newSubmissionFile->setSubmissionLocale($submission->getLocale());
						$newSubmissionFile->setGenreId($genreId);
						$newSubmissionFile->setFileStage($fileStage);
						$newSubmissionFile->setDateUploaded(Core::getCurrentDate());
						$newSubmissionFile->setDateModified(Core::getCurrentDate());
						$newSubmissionFile->setUploaderUserId($user->getId());
						$newSubmissionFile->setFileSize($fileSize);
						$newSubmissionFile->setFileType($fileType);

						$newSubmissionFile->setOriginalFileName($clientFileName);
						$newSubmissionFile->setSourceRevision($submissionFile->getRevision());
						$newSubmissionFile->setSourceFileId($sourceFileId);
						$insertedSubmissionFile = $submissionFileDao->insertObject($newSubmissionFile, $filePath);

						foreach ($dependentFiles as $asset) {

							$genreId = $this->_getGenreId($request, $asset->getType());
							$this->_createDependentFile($genreId, $submission, $asset->getFileName(), SUBMISSION_FILE_DEPENDENT, ASSOC_TYPE_SUBMISSION_FILE, true, $insertedSubmissionFile->getFileId(), $asset->getPathname(), $request);
						}

					} else {

						return $this->removeFilesAndNotify($zip, $archivePath, $user, __('plugins.generic.texture.notification.noValidHTMLFile'));
					}


				}


			}
		} else {
			return $this->removeFilesAndNotify($zip, $archivePath, $user, __('plugins.generic.texture.notification.noValidDarFile'));
		}

		return $this->removeFilesAndNotify($zip, $archivePath, $user, __('plugins.generic.texture.notification.extracted'), PKPNotification::NOTIFICATION_TYPE_SUCCESS, true);
	}

	/**
	 * @param $genres
	 * @param $extension
	 * @return mixed
	 */
	private function _getGenreId($request, $extension) {

		error_log('TextureHandler::_getGenreId called');

		$genreId = null;
		$journal = $request->getJournal();
		
		// Usar DAORegistry para géneros ya que Repo::genre() no existe en OJS 3.4
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$genres = $genreDao->getEnabledByContextId($journal->getId());
		
		while ($genre = $genres->next()) {
			if ($extension) {
				if ($genre->getKey() == 'IMAGE') {
					$genreId = $genre->getId();
					break;
				}
			} else {
				if ($genre->getKey() == 'MULTIMEDIA') {
					$genreId = $genre->getId();
					break;
				}
			}
		}
		
		return $genreId;
	}

	/**
	 * Creates a dependent file
	 *
	 * @param $genreId  int
	 * @param $submission Submission
	 * @param $fileName string
	 * @param bool $fileStage
	 * @param bool $assocType
	 * @param bool $deletePath
	 * @param bool $assocId
	 * @param bool $filePath string
	 * @param $request
	 * @return void
	 */
	protected function _createDependentFile($genreId, $submission, $fileName, $fileStage = false, $assocType = false, $deletePath = false, $assocId = false, $filePath = false, $request) {

		error_log('TextureHandler::createDependentFile called');
		$submissionFile = DAORegistry::getDao('SubmissionFileDAO')->newDataObject();

		$submissionFile->setData('submissionFileId', $submissionFile->getData("submissionFileId"));
		$submissionFile->setData('fileStage', $fileStage);
		$submissionFile->setData('name', $fileName);
		$submissionFile->setData('submissionId', $submission->getData('submissionId'));
		$submissionFile->setData('uploaderUserId', $request->getUser()->getId());
		$submissionFile->setData('assocType', $assocType);
		$submissionFile->setData('assocId', $assocId);
		$submissionFile->setData('genreId', (int)$genreId);
		//Services::get('submissionFile')->add($submissionFile, $request);
		Repo::submissionFile()->add($submissionFile, $request);
		if ($deletePath) unlink($filePath);
		
	}

	/**
	 * Remove files and notify
	 * @param ZipArchive $zip
	 * @param string $archivePath
	 * @param $user
	 * @param  $message
	 * @param $errorType
	 * @param bool $status
	 * @return JSONMessage
	 */
	private function removeFilesAndNotify(ZipArchive $zip, string $archivePath, $user, $message, $errorType = PKPNotification::NOTIFICATION_TYPE_ERROR, $status = False): JSONMessage {
		error_log('TextureHandler::removeFilesAndNotify called');
		$notificationMgr = new NotificationManager();
		$zip->close();
		$this->rrmdir($archivePath);
		$notificationMgr->createTrivialNotification($user->getId(), $errorType, array('contents' => $message));
		return new JSONMessage($status);
	}

	/**
	 * Delete folder and its contents
	 * @note Adapted from https://www.php.net/manual/de/function.rmdir.php#117354
	 */
	private function rrmdir($src) {
		error_log('TextureHandler::rrmdir called');
		$dir = opendir($src);
		while (false !== ($file = readdir($dir))) {
			if (($file != '.') && ($file != '..')) {
				$full = $src . '/' . $file;
				if (is_dir($full)) {
					$this->rrmdir($full);
				} else {
					unlink($full);
				}
			}
		}
		closedir($dir);
		rmdir($src);
	}

	/**
	 * Exports a DAR Archive
	 * @param $args
	 * @param $request PKPRequest
	 * @return
	 */
	public function export($args, $request) {

		error_log('TextureHandler::export called');


		$dar = new DAR();
		$assets = array();

		$context = $request->getContext();
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		$filePath = $submissionFile->getData('path');
		$manuscriptXml = file_get_contents($filePath);
		$manifestXml = $dar->createManifest($manuscriptXml, $assets);

		$submissionId = $request->getUserVar('submissionId');
		$fileManager = $this->_getFileManager($context->getId(), $submissionFile->getId());
		$assetsFilePaths = $dar->getDependentFilePaths($submissionId, $submissionFile->getId());

		$archivePath = tempnam('/tmp', 'texture-');
		if (self::zipFunctional()) {
			$zip = new ZipArchive();

			if ($zip->open($archivePath, ZIPARCHIVE::CREATE) == true) {
				$zip->addFile($filePath, DAR_MANUSCRIPT_FILE);
				$zip->addFromString(DAR_MANIFEST_FILE, $manifestXml);
				foreach ($assetsFilePaths as $name => $path) {
					$zip->addFile($path, $name);
				}
				$zip->close();
			}
		}

		if (file_exists($archivePath)) {
			$fileManager->downloadByPath($archivePath, 'application/x-zip', false, pathinfo($filePath, PATHINFO_FILENAME) . '.dar');
			$fileManager->deleteByPath($archivePath);
		} else {
			fatalError('Creating archive with submission files failed!');
		}
	}

	/**
	 * return the application specific file manager.
	 * @param $contextId int the context for this manager.
	 * @param $submissionId int the submission id.
	 * @return SubmissionFileManager
	 */
	function _getFileManager($contextId, $submissionId) {
		error_log('TextureHandler::_getFileManager called');
		return new SubmissionFileManager($contextId, $submissionId);
	}

	/**
	 * Return true if the zip extension is loaded.
	 * @return boolean
	 */
	static function zipFunctional() {
		error_log('TextureHandler::zipFunctional called');
		return (extension_loaded('zip'));
	}

	/**
	 * @param $args
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	public function createGalley($args, $request) {

		error_log('TextureHandler::createGalley called');		
		import('plugins.generic.texture.controllers.grid.form.TextureArticleGalleyForm');
		$galleyForm = new TextureArticleGalleyForm($request, $this->getPlugin(), $this->publication, $this->submission);
		$galleyForm->readInputData();

		if ($galleyForm->validate()) {
			$galleyForm->execute();
			return $request->redirectUrlJson($request->getDispatcher()->url($request, ROUTE_PAGE, null, 'workflow', 'access', null,
				array(
					'submissionId' => $request->getUserVar('submissionId'),
					'stageId' => $request->getUserVar('stageId')
				)
			));
		}

		return new JSONMessage(false);
	}

	/**
	 * Display substance editor
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string
	 */
	public function editor($args, $request) {

		error_log('TextureHandler::editor called');

		$stageId = (int)$request->getUserVar('stageId');
		$submissionFileId = (int)$request->getUserVar('submissionFileId');
		$submissionId = (int)$request->getUserVar('submissionId');
		if (!$submissionId || !$stageId || !$submissionFileId) {
			fatalError('Invalid request');
		}
		$submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
		$editorTemplateFile = method_exists($this->_plugin, 'getTemplateResource') ? $this->_plugin->getTemplateResource('editor.tpl') : ($this->_plugin->getTemplateResourceName() . ':templates/editor.tpl');
		$router = $request->getRouter();
		$documentUrl = $router->url($request, null, 'texture', 'json', null,
			array(
				'submissionId' => $submissionId,
				'submissionFileId' => $submissionFileId,
				'stageId' => $stageId
			)
		);

		//not necessary
		//AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
		$templateMgr = TemplateManager::getManager($request);
		$publication = $submission->getCurrentPublication();
		$title = $publication->getLocalizedData('title') ?? __('plugins.generic.texture.name');

		$templateMgr->assign(array(
			'documentUrl' => $documentUrl,
			'textureUrl' => $this->_plugin->getTextureUrl($request),
			'texturePluginUrl' => $this->_plugin->getPluginUrl($request),
			'title' => $title
		));
		return $templateMgr->fetch($editorTemplateFile);
	}

	/**
	 * fetch json archive
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	public function json($args, $request) {

		error_log('TextureHandler::json called');

	    $dar = new DAR();
		$submissionFileId = (int)$request->getUserVar('submissionFileId');
		
		// Remplace with the actual way to get the submission file
		//$submissionFile = Services::get('submissionFile')->get($submissionFileId);
		$submissionFile = Repo::submissionFile()->get($submissionFileId);

		$context = $request->getContext();
		$submissionId = (int)$request->getUserVar('submissionId');
		if (!$submissionFile) {
			fatalError('Invalid request');
		}

		if (empty($submissionFile)) {
			echo __('plugins.generic.texture.archive.noArticle'); // TODO custom message
			exit;
		}

		$formLocales = Locale::getSupportedFormLocales();
		error_log('($_SERVER["REQUEST_METHOD"]' . $_SERVER["REQUEST_METHOD"]);
		if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
			
			$postData = file_get_contents('php://input');
			$media = (array)json_decode($postData);
			if (!empty($media)) {


				$dependentFilesIterator = Repo::submissionFile()
					->getCollector()
					->filterBySubmissionIds([$submissionId])
					->filterByFileStages([SUBMISSION_FILE_DEPENDENT])
					->getMany()
					->filter(function($file) use ($submissionFileId) {
						return $file->getData('assocType') === ASSOC_TYPE_SUBMISSION_FILE 
							&& $file->getData('assocId') === $submissionFileId;
					});


				foreach ($dependentFilesIterator as $dependentFile) {

					$fileName = $dependentFile->getLocalizedData('name');

						if ($fileName == $media['fileName']) {
							Repo::submissionFile()->delete($dependentFile);

					}
				}
			}
		}

		if ($_SERVER["REQUEST_METHOD"] === "GET") {
			$mediaBlob = $dar->construct($dar, $request, $submissionFile);
			header('Content-Type: application/json');

			return json_encode($mediaBlob, JSON_UNESCAPED_SLASHES);
		} elseif ($_SERVER["REQUEST_METHOD"] === "PUT") {
			$postData = file_get_contents('php://input');

			if (!empty($postData)) {
				$submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
				$postDataJson = json_decode($postData);
				$resources = (isset($postDataJson->archive) && isset($postDataJson->archive->resources)) ? (array)$postDataJson->archive->resources : [];
				//todo extract media correctly
				$media = isset($postDataJson->media) ? (array)$postDataJson->media : [];

				if (!empty($media) && array_key_exists("data", $media)) {
					
					$fileManager = new FileManager();
					$extension = $fileManager->parseFileExtension($media["fileName"]);

					$genreId = $this->_getGenreId($request, $extension);
					if (!$genreId) {
						return new JSONMessage(false);
					}

					$mediaBlob = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $media["data"]));
					$tempMediaFile = tempnam(sys_get_temp_dir(), 'texture');
					file_put_contents($tempMediaFile, $mediaBlob);

					
					$fileManager = new FileManager();
					$extension = $fileManager->parseFileExtension($media['fileName']);
					//$submissionDir = Services::get('submissionFile')->getSubmissionDir($context->getData('id'), $submission->getData('id'));
					$submissionDir = Repo::submissionFile()->getSubmissionDir($context->getData('id'), $submission->getData('id'));
					$fileId = Services::get('file')->add($tempMediaFile, $submissionDir . '/' . uniqid() . '.' . $extension);
					unlink($tempMediaFile);

					$newSubmissionFile = Repo::submissionFile()->newDataObject();
					$newSubmissionFile->setData('fileId', $fileId);
					$newSubmissionFile->setData('name', array_fill_keys(array_keys($formLocales), $media["fileName"]));
					$newSubmissionFile->setData('submissionId', $submission->getData('id'));
					$newSubmissionFile->setData('uploaderUserId', $request->getUser()->getId());
					$newSubmissionFile->setData('assocType', ASSOC_TYPE_SUBMISSION_FILE);
					$newSubmissionFile->setData('assocId', $submissionFile->getData('id'));
					$newSubmissionFile->setData('genreId', $this->_getGenreId($request, $extension));
					$newSubmissionFile->setData('fileStage', SUBMISSION_FILE_DEPENDENT);

					//Services::get('submissionFile')->add($newSubmissionFile, $request);
					Repo::submissionFile()->add($newSubmissionFile, $request);

				} elseif (!empty($resources) && isset($resources[DAR_MANUSCRIPT_FILE]) && is_object($resources[DAR_MANUSCRIPT_FILE])) {
					$this->_updateManuscriptFile($request, $resources, $submission, $submissionFile);
				} else {
					return new JSONMessage(false);
				}

				return new JSONMessage(true);
			}
		} else {
			return new JSONMessage(false);
		}
	}

	/**
	 * Update manuscript XML file
	 * @param $request
	 * @param $resources  array
	 * @param $submission Article
	 * @param $submissionFile SubmissionFile
	 * @return SubmissionFile
	 */
	protected function _updateManuscriptFile($request, $resources, $submission, $submissionFile) {

		error_log('TextureHandler::_updateManuscriptFile called');
		$modifiedDocument = new \DOMDocument('1.0', 'utf-8');
		$modifiedData = $resources[DAR_MANUSCRIPT_FILE]->data;
		$context = $request->getContext();

		// write metada back from  original file
		$modifiedDocument->loadXML($modifiedData);
		$xpath = new \DOMXpath($modifiedDocument);
		
		$manuscriptXml = Services::get('file')->fs->read($submissionFile->getData('path'));

		$origDocument = new \DOMDocument('1.0', 'utf-8');
		$origDocument->loadXML($manuscriptXml);

		$body = $origDocument->documentElement->getElementsByTagName('body')->item(0);
		$origDocument->documentElement->removeChild($body);

		$manuscriptBody = $xpath->query("//article/body");
		foreach ($manuscriptBody as $content) {
			$node = $origDocument->importNode($content, true);
			$origDocument->documentElement->appendChild($node);
		}

		$back = $origDocument->documentElement->getElementsByTagName('back')->item(0);
		$origDocument->documentElement->removeChild($back);

		$manuscriptBack = $xpath->query("//article/back");
		foreach ($manuscriptBack as $content) {
			$node = $origDocument->importNode($content, true);
			$origDocument->documentElement->appendChild($node);
		}

		$tmpfname = tempnam(sys_get_temp_dir(), 'texture');
		file_put_contents($tmpfname, $origDocument->saveXML());
		
		$fileManager = new FileManager();
		$extension = $fileManager->parseFileExtension($submissionFile->getData('path'));
		
		//$submissionDir = Services::get('submissionFile')->getSubmissionDir($context->getData('id'), $submission->getData('id'));
		$submissionDir = Repo::submissionFile()->getSubmissionDir($context->getData('id'), $submission->getData('id'));

		$fileId = Services::get('file')->add($tmpfname, $submissionDir . '/' . uniqid() . '.' . $extension);

		//Services::get('submissionFile')->edit($submissionFile, ['fileId' => $fileId, 'uploaderUserId' => $request->getUser()->getId(),], $request);
		Repo::submissionFile()->edit($submissionFile, ['fileId' => $fileId, 'uploaderUserId' => $request->getUser()->getId(),], $request);

		unlink($tmpfname);

		error_log('TextureHandler::_updateManuscriptFile FIN DEL PROCESO');

		return $fileId;
	}

	/**
	 * display images attached to XML document
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 * @return void
	 */
	public function media($args, $request) {
		$submissionFileId = (int)$request->getUserVar('assocId');
		$requestedFileId = (int)$request->getUserVar('fileId');
		
		error_log("TextureHandler::media - assocId: $submissionFileId, fileId: $requestedFileId");

		$submissionFile = Repo::submissionFile()->get($submissionFileId);
		
		if (!$submissionFile || !in_array($submissionFile->getData('mimetype'), array('text/xml', 'application/xml'))) {
			fatalError('Invalid request');
		}

		// Buscar archivos dependientes asociados con este archivo XML en la etapa actual
		$dependentFiles = Repo::submissionFile()
			->getCollector()
			->filterByAssoc(ASSOC_TYPE_SUBMISSION_FILE, [$submissionFile->getData('id')])
			->filterBySubmissionIds([$submissionFile->getData('submissionId')])
			->filterByFileStages([SUBMISSION_FILE_DEPENDENT])
			->getMany();
		
		// 1. PRIMERO: Buscar coincidencia directa por ID en la etapa actual
		$mediaFile = null;
		foreach ($dependentFiles as $dependentFile) {
			if ($dependentFile->getData('fileId') == $requestedFileId) {
				$mediaFile = $dependentFile;
				error_log("Found file by ID match in current stage");
				break;
			}
		}

		// 2. SEGUNDO: Si no se encuentra por ID, buscar por nombre
		if (!$mediaFile) {
			// Obtener el nombre del archivo solicitado buscando en toda la presentación
			$requestedFileName = null;
			$allFiles = Repo::submissionFile()
				->getCollector()
				->filterBySubmissionIds([$submissionFile->getData('submissionId')])
				->getMany();
				
			foreach ($allFiles as $file) {
				if ($file->getData('fileId') == $requestedFileId) {
					$requestedFileName = $file->getLocalizedData('name');
					error_log("Found name for requested fileId: " . $requestedFileName);
					break;
				}
			}
			
			// Si se encontró el nombre, buscar un archivo con ese nombre en la etapa actual
			if ($requestedFileName) {
				foreach ($dependentFiles as $dependentFile) {
					$currentName = $dependentFile->getLocalizedData('name');
					if ($currentName === $requestedFileName) {
						$mediaFile = $dependentFile;
						error_log("Found matching file by name: " . $currentName);
						break;
					}
				}
			}
		}

		// Si no se encuentra, devolver un placeholder transparente
		if (!$mediaFile) {
			$request->getDispatcher()->handle404();
		}

		// Servir el archivo de medios
		error_log("Serving media file: " . $mediaFile->getLocalizedData('name'));
		header('Content-Type:' . $mediaFile->getData('mimetype'));
		$mediaFileContent = Services::get('file')->fs->read($mediaFile->getData('path'));
		header('Content-Length: ' . strlen($mediaFileContent));
		return $mediaFileContent;
	}

}

if (!PKP_STRICT_MODE) {
    class_alias('APP\plugins\generic\texture\TextureHandler', '\TextureHandler');
}
