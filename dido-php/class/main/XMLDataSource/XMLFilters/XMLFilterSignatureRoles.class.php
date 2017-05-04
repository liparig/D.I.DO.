<?php 
class XMLFilterSignatureRoles extends AXMLFilter implements IXMLFilter{
	public function apply(&$list){
		$this->init();
		
		foreach($list as $catName=>$data){
			foreach($data as $tipoDocumento=>$versioni){
				foreach($versioni as $nv=>$xmlData){
					$this->_XMLParser->setXMLSource($xmlData['xml']);
					
					if(!$this->_XMLParser->isSigner($this->_filters))
						unset($list[$catName][$tipoDocumento][$nv]);
				}
				if(empty($list[$catName][$tipoDocumento]))
					unset($list[$catName][$tipoDocumento]);
			}
			if(empty($list[$catName]))
				unset($list[$catName]);
		}
	}
}