<?php 
class Application_Detail{
	private $_userManager;
	private $_ProcedureManager;
	
	private $_defaultDocumentInputs;
	private $_Signature;
	private $_sigRoles;
	private $_SignatureChecker;
	
	private $_redirectUrl;
	private $_info;
	private $_flowResults;
	
	public function __construct(IDBConnector $dbConnector, IUserManager $userManager, IFTPDataSource $ftpDataSource){
		$this->_userManager = $userManager;
		$this->_ProcedureManager = new ProcedureManager($dbConnector, $ftpDataSource);
		
		$XMLParser = new XMLParser();
		$XMLParser->load(FILES_PATH."defaultDocumentsInputs.xml");

		$this->_defaultDocumentInputs = $XMLParser->getXmlSource()->input;
		$this->_Signature = new Signature($dbConnector);
		$sigRoles = new SignersRoles($dbConnector);
		$this->_sigRoles = Utils::getListfromField($sigRoles->getAll(),SignersRoles::DESCRIZIONE, SignersRoles::SIGLA);
		$this->_SignatureChecker = new SignatureChecker($ftpDataSource);
	}
	
	public function createDetail($md, $mdLinks){
		flog("resultTree: %o",$md);
		extract($md);
	
		$id_md = $md[Masterdocument::ID_MD];
		$MDSigners = $this->_Signature->getSigners($id_md, $md_data);
		
		$XMLParser = new XMLParser(
			$md[Masterdocument::XML],
			$md[Masterdocument::TYPE]
		);
		
		$this->_redirectUrl = TurnBack::getLastHttpReferer()."#".dirname($md[Masterdocument::XML])."/#".Common::fieldFromLabel($md[Masterdocument::NOME]);
		$this->_createAdditionalInfo($XMLParser, $id_md, $md_data);
		$this->_flowResults = new FlowTimeline();
		
		
		$ICanManageIt =
			$this->_userManager->isAdmin() ||
			($this->_userManager->isGestore(true) && $XMLParser->isOwner($this->_userManager->getUser()->getGruppi()));

		$almostOne = false;
		// L'elenco dei documenti lo prendo sempre dall'XML
		foreach($XMLParser->getDocList() as $doc){
			
			if(isset($doc[XMLParser::MD])){
				// il documento in realtà è un Master Document esterno
				$docName = (string)$doc[XMLParser::MD];
				$listOnDb = Utils::filterList($mdLinks[Application_DocumentBrowser::LABEL_MD], Masterdocument::NOME, $docName);
				flog("listOnDb: %o",$listOnDb);
				if(count($listOnDb) == 0){
					$this->_flowResults->addTimelineElement(
						new TimelineElementMissing(ucfirst($docName), (int)$doc[XMLParser::MIN_OCCUR], $ICanManageIt, "?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_ADD_MD_LINK."&".XMLParser::DOC_NAME."=$docName&".Masterdocument::ID_MD."={$id_md}", true)
					);
				
					# Se ne serve almeno uno si blocca il rendering
					if($doc[XMLParser::MIN_OCCUR])
						break;				
				} else {
					if(!$this->_parseMdLink($listOnDb, (int)$doc[XMLParser::MIN_OCCUR], (int)$doc[XMLParser::MAX_OCCUR], $mdLinks[Application_DocumentBrowser::LABEL_MD_DATA], $ICanManageIt))
						break;
					$almostOne = true;
					flog("almostOne");
				}
			} else {
				$XMLParser->checkIfMustBeLoaded ( $doc );
				$docName = (string)$doc[XMLParser::DOC_NAME];
				
				$listOnDb = Utils::filterList($documents, Document::NOME, $docName);
				if(count($listOnDb) == 0){
					$this->_flowResults->addTimelineElement(
						new TimelineElementMissing(ucfirst($docName), (int)$doc[XMLParser::MIN_OCCUR], $ICanManageIt, "?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_UPLOAD."&".XMLParser::DOC_NAME."=$docName&".Masterdocument::ID_MD."={$id_md}")
					);
				
					if($doc[XMLParser::MIN_OCCUR])
						break;				
				} else {
					//Utils::Printr($listOnDb);
					if(!$this->_parse($listOnDb, (int)$doc[XMLParser::MIN_OCCUR], (int)$doc[XMLParser::MAX_OCCUR], $md, $documents, $documents_data, $ICanManageIt, $XMLParser->getDocumentInputs($docName), $XMLParser->getDocumentSignatures($docName), $MDSigners))
						break;
					$almostOne = true;
				}
			}
			
		}
		
		if(!$almostOne)
			return;
		// Ora si aggiunge eventuali allegati se c'è almeno il primo documento caricato
		$XMLParser->load(XML_STD_PATH."allegato.xml");
		$docToSearch = $XMLParser->getXmlSource();
		$docInputs = $docToSearch->inputs->input;
		$docName = $docToSearch[XMLParser::DOC_NAME];
		$listOnDb = Utils::filterList($documents, Document::NOME, $docName);
		
		
		if(count($listOnDb)){
			$this->_parse($listOnDb, (int)$docToSearch[XMLParser::MIN_OCCUR], (int)$docToSearch[XMLParser::MAX_OCCUR], $md, $documents, $documents_data, $ICanManageIt, $docInputs);
		} else {
			if($this->_userManager->isGestore()){
				$this->_flowResults->addTimelineElement(
						new TimelineElementMissing(ucfirst($docName), false, $ICanManageIt, "?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_UPLOAD."&".XMLParser::DOC_NAME."=$docName&".Masterdocument::ID_MD."={$id_md}")
				);
			}
		} 
	}
	
	private function _parseMdLink($listOnDb, $lowerLimit, $upperLimit, $documents_data, $ICanManageIt){
		flog("listOnDB: %o",$listOnDb);
		flog("data: %o",$documents_data);
		foreach($listOnDb as $id_doc => $docData){
			
		}
		return true;
		
	}
	
	private function _parse($listOnDb, $lowerLimit, $upperLimit, $md, $documents, $documents_data, $ICanManageIt, $docInputs, $signatures = false, $MDSigners = null){
		$id_md = $md[Masterdocument::ID_MD];
		foreach($listOnDb as $id_doc => $docData){
				
			$docName = $documents[$id_doc][Document::NOME];
			
			$documentClosed = $documents[$id_doc][Document::CLOSED] == ProcedureManager::CLOSED;
				
			$IMustSignIt =
				$documents[$id_doc][Application_DocumentBrowser::MUST_BE_SIGNED_BY_ME] &&
				!$documents[$id_doc][Application_DocumentBrowser::IS_SIGNED_BY_ME];
			
			$docPath =
				$md[Masterdocument::FTP_FOLDER] .
				Common::getFolderNameFromMasterdocument($md) .
				DIRECTORY_SEPARATOR .
				Common::getFilenameFromDocument($documents[$id_doc]);
				
			$docInfo = $this->createDocumentInfoPanel($docInputs, $documents_data[$id_doc]);
				
			$editInfoBTN =
			($ICanManageIt && !$documentClosed) ?
			new FlowTimelineButtonEditInfo("?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_EDIT_INFO."&".Masterdocument::ID_MD."={$id_md}&".Document::ID_DOC."={$id_doc}") :
			null;
		
				
			$docSignatures = $this->_createDocumentSignaturesPanel($docPath, $signatures, $MDSigners);
				
			$panelBody = new FlowTimelinePanelBody($docInfo, !is_null($editInfoBTN) ? $editInfoBTN->get() : null, $docSignatures['html']);
			$panelButtons = [];
				
			// Posso caricare il documento se:
			// - il documento non è chiuso e
			// - posso gestirlo o devo firmarlo
			if( !$documentClosed && ( $ICanManageIt || $IMustSignIt ) )
				array_push($panelButtons, new FlowTimelineButtonUpload("?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_UPLOAD."&".Masterdocument::ID_MD."={$id_md}&".Document::ID_DOC."={$id_doc}"));
				
			// Di default lo posso scaricare sempre
			array_push($panelButtons, new FlowTimelineButtonDownload("?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_DOWNLOAD."&".Masterdocument::ID_MD."={$id_md}&".Document::ID_DOC."={$id_doc}"));

			// Se non c'è il maxoccur o comunque il numero di documenti è inferiore al maxoccur posso caricarne di nuovi
			if(!$upperLimit || count($listOnDb) < $upperLimit)
				array_push($panelButtons, new FlowTimelineButtonAdd("?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_UPLOAD."&".XMLParser::DOC_NAME."=".$docName."&".Masterdocument::ID_MD."={$id_md}"));
			
			// Se non c'è il minoccur o comunque minOccur = 0
			if(!$lowerLimit || ($lowerLimit >= 1 && count($listOnDb) > $lowerLimit))
				array_push($panelButtons, new FlowTimelineButtonDelete("?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_DELETE."&".Masterdocument::ID_MD."={$id_md}&".Document::ID_DOC."={$id_doc}"));
			
			// Il documento se non ci sono errori e non è già chiuso lo posso chiudere
			if(!($signatures && $docSignatures['errors']) && !$documentClosed)
				array_push($panelButtons, new FlowTimelineButtonCloseDocument("?".Application_ActionManager::ACTION_LABEL."=".Application_ActionManager::ACTION_CLOSE_DOC."&".Masterdocument::ID_MD."={$id_md}&".Document::ID_DOC."={$id_doc}"));
				
			$panel = new FlowTimelinePanel(ucfirst($docName), $panelButtons, $panelBody);
				
			$badge =
				($signatures && $docSignatures['errors']) || !$documentClosed ?
				new FlowTimelineBadgeWarning() :
				new FlowTimelineBadgeSuccess($documentClosed);
					
			$this->_flowResults->addTimelineElement(
					new TimelineElementFull($badge, $panel),
					$id_doc
			);
				
			// Se ci sono errori oppure il documento risulta ancora aperto si salta tutto il resto.
			if(($signatures && $docSignatures['errors']) || !$documentClosed )
				return false;
		}
		
		return true;
	}
	
	public function createDocumentInfoPanel($inputs, $docData, $readonly = true, $mdInfo = false){
		
		$docInfo = FormHelper::createInputs($inputs, $docData, $readonly);
		if(!$mdInfo) $docInfo .= FormHelper::createInputs($this->_defaultDocumentInputs, $docData, $readonly);
		
		return $docInfo;
	}
	
	public function updateDocumentData($id_doc, $docInputs, $documents_data){
		$documents_data = Common::createPostMetadata($documents_data,$id_doc);
		$result = $this->_ProcedureManager->updateDocumentData($documents_data);
		return new ErrorHandler($result ? false : "Impossibile aggiornare i dati");
	}

	private function _createDocumentSignaturesPanel($docPath, $docSignatures, $MDSigners){
		if(!$docSignatures) 
			return null;
		
		$signResult = [
			'errors' => false,
			'html'		=> []	
		];
		
		$this->_SignatureChecker->load($docPath);
		
		foreach($docSignatures as $signature){
			$role = (string)$signature[XMLParser::ROLE];
			/*if($role == "REQ") continue;*/
			if(!isset($MDSigners[$role])){
				$signResult['errors'] = true;
				$signResult['html'][] = "<div class=\"alert alert-danger\"><span class=\"fa fa-times\"></span> Manca la firma del {$this->_sigRoles [$role]} nel sistema DIDO!!!</div>";
				continue;
			}
			
			$who = $MDSigners[$role];
			
			$whoIs = Personale::getInstance()->getNominativo($who[Signers::ID_PERSONA]);
		
			if($this->_SignatureChecker->checkSignature($who[Signature::PKEY])){
				$signResult['html'][] = "<div class=\"alert alert-success\"><span class=\"fa fa-check\"></span> {$whoIs} ({$who[SignersRoles::DESCRIZIONE]}) </div>";
				break;
			}
			
			if($this->_SignatureChecker->checkSignature($who[Signature::PKEY_DELEGATO])){
				$whoIs_Delegato = Personale::getInstance()->getNominativo($who[Signature::ID_DELEGATO]);
				$signResult['html'][] = "<div class=\"alert alert-success\"><span class=\"fa fa-check\"></span> {$whoIs_Delegato} - delegato da {$whoIs} ({$who[SignersRoles::DESCRIZIONE]}) </div>";
				break;
			}
			$signResult['html'][] = "<div class=\"alert alert-warning\"><span class=\"fa fa-warning\"></span> Manca la firma di {$whoIs} ({$who[SignersRoles::DESCRIZIONE]})</div>";
		}
		$signResult['html'] = join(PHP_EOL,$signResult['html']);
		return $signResult;
	}
	
	public function getRedirectUrl(){
		return $this->_redirectUrl;
	}
	
	public function getReadOnly(){
		return $this->_readOnly;
	}
	
	public function info(){
		return $this->_info;
	}
	
	public function getFlowResults(){
		return $this->_flowResults;
	}
	
	private function _createAdditionalInfo($XMLParser, $id_md, $md_data){
		$readOnly =
		!is_null($XMLParser->getSource()) ?
		true : (
				// - L'utente non è proprietario del documento oppure lo è ma non ha i permessi per editarli
				$this->_userManager->isAdmin() ||
				($XMLParser->isOwner($this->_userManager->getUser()->getGruppi()) && $this->_userManager->isGestore(true)) ?
				false :
				true
		);
		
		$infoPanel = FormHelper::createInputs($XMLParser->getMasterDocumentInputs(), $md_data, true);
		if(!$readOnly)
			$infoPanel .= "<div class=\"text-center\"><a href=\"?action=".Application_ActionManager::ACTION_EDIT_MD_INFO."&".Masterdocument::ID_MD."=".$id_md."\" class=\"btn btn-primary ".Application_ActionManager::ACTION_EDIT_MD_INFO."\">Modifica informazioni</a></div>";
		
		$this->_info = $infoPanel;
	}	
	
}
?>