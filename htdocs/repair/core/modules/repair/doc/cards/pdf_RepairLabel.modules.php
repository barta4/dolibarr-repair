<?php
/* Copyright (C) 2004-2012	Laurent Destailleur	<eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012	Regis Houssin		<regis@dolibarr.fr>
 * Copyright (C) 2008		Raphael Bertrand	<raphael.bertrand@resultic.fr>
 * Copyright (C) 2010-2012	Juanjo Menent		<jmenent@2byte.es>
 * Copyright (C) 2013      Pierre-Emmanuel DOUET	<tathar.dev@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *	\file       htdocs/repair/core/modules/repair/doc/pdf_squeeze.modules.php
 *	\ingroup    repair
 *	\brief      Fichier de la classe permettant de generer les repairs au modele Squeeze
 */

require_once DOL_DOCUMENT_ROOT .'/repair/core/modules/repair/modules_repair.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';


/**
 *	Classe permettant de generer les repairs au modele Squeeze
 */
class pdf_RepairLabel extends ModeleRepair
{
    var $db;
    var $name;
    var $description;
    var $type;

    var $phpmin = array(4,3,0); // Minimum version of PHP required by module
    var $version = '0.1';

    var $page_largeur;
    var $page_hauteur;
    var $format;
	var $marge_gauche;
	var	$marge_droite;
	var	$marge_haute;
	var	$marge_basse;

    var $emetteur;	// Objet societe qui emet


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	function __construct($db)
	{
		global $conf,$langs,$mysoc;

		$langs->load("main");
		$langs->load("bills");

		$this->db = $db;
		$this->name = "Repair Label";
		$this->description = $langs->trans('PDFRepairLabel');

		// Dimension page pour format A5
		$this->type = 'pdf';
		$formatarray=pdf_getFormat();
		$this->page_largeur = 210;
		$this->page_hauteur = 148;
//		$formatarray =array('width'=>$this->page_largeur,'height'=>$this->page_hauteur,'unit'=>'mm');	
		$this->format = array($this->page_largeur,$this->page_hauteur);
		$this->marge_gauche=isset($conf->global->MAIN_PDF_MARGIN_LEFT)?$conf->global->MAIN_PDF_MARGIN_LEFT:10;
		$this->marge_droite=isset($conf->global->MAIN_PDF_MARGIN_RIGHT)?$conf->global->MAIN_PDF_MARGIN_RIGHT:10;
		$this->marge_haute =isset($conf->global->MAIN_PDF_MARGIN_TOP)?$conf->global->MAIN_PDF_MARGIN_TOP:10;
		$this->marge_basse =isset($conf->global->MAIN_PDF_MARGIN_BOTTOM)?$conf->global->MAIN_PDF_MARGIN_BOTTOM:10;

		$this->option_logo = 0;                    // Affiche logo
		$this->option_tva = 0;                     // Gere option tva FACTURE_TVAOPTION
		$this->option_modereg = 0;                 // Affiche mode reglement
		$this->option_condreg = 0;                 // Affiche conditions reglement
		$this->option_codeproduitservice = 0;      // Affiche code produit-service
		$this->option_multilang = 1;               // Dispo en plusieurs langues
		$this->option_escompte = 0;                // Affiche si il y a eu escompte
		$this->option_credit_note = 0;             // Support credit notes
		$this->option_freetext = 1;				   // Support add of a personalised text
		$this->option_draft_watermark = 1;		   // Support add of a watermark on drafts

		$this->franchise=!$mysoc->tva_assuj;

		// Get source company
		$this->emetteur=$mysoc;
		if (empty($this->emetteur->country_code)) $this->emetteur->country_code=substr($langs->defaultlang,-2);    // By default, if was not defined

		// Define position of columns
		$this->posxdesc=$this->marge_gauche+1;
		$this->posxtva=111;
		$this->posxup=126;
		$this->posxqty=145;
		$this->posxdiscount=162;
		$this->postotalht=174;
		if ($this->page_largeur < 210) // To work with US executive format
		{
			$this->posxtva-=20;
			$this->posxup-=20;
			$this->posxqty-=20;
			$this->posxdiscount-=20;
			$this->postotalht-=20;
		}

		$this->tva=array();
		$this->localtax1=array();
		$this->localtax2=array();
		$this->localtax1_type=array();
		$this->localtax2_type=array();
		$this->atleastoneratenotnull=0;
		$this->atleastonediscount=0;

		
	}

	/**
     *  Function to build pdf onto disk
     *
     *  @param		Object		$object				Object to generate
     *  @param		Translate	$outputlangs		Lang output object
     *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
     *  @param		int			$hidedetails		Do not show line details
     *  @param		int			$hidedesc			Do not show desc
     *  @param		int			$hideref			Do not show ref
     *  @param		object		$hookmanager		Hookmanager object
     *  @return     int             			1=OK, 0=KO
	 */
	function write_file($object,$outputlangs,$srctemplatepath='',$hidedetails=0,$hidedesc=0,$hideref=0,$hookmanager=false)
	{
		global $user,$langs,$conf;

		if (! is_object($outputlangs)) $outputlangs=$langs;
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (! empty($conf->global->MAIN_USE_FPDF)) $outputlangs->charset_output='ISO-8859-1';

		$outputlangs->load("main");
		$outputlangs->load("dict");
		$outputlangs->load("companies");
		$outputlangs->load("bills");
		$outputlangs->load("products");
		$outputlangs->load('sendings');
		$outputlangs->load("repairlang@repair");

		if ($conf->repair->dir_output)
		{
            $object->fetch_thirdparty();

            $deja_regle = "";

            // Definition of $dir and $file
			if ($object->specimen)
			{
				$dir = $conf->repair->dir_output;
				$file = $dir . "/SPECIMEN.pdf";
			}
			else
			{
				$objectref = dol_sanitizeFileName($object->ref);
				$dir = $conf->repair->dir_output . "/" . $objectref;
				$file_ext=strtolower($langs->transnoentities(RepairGenerateCard));
				$file = $dir . "/" . $objectref . "_".$file_ext.".pdf";
			}

			if (! file_exists($dir))
			{
				if (dol_mkdir($dir) < 0)
				{
					$this->error=$langs->transnoentities("ErrorCanNotCreateDir",$dir);
					return 0;
				}
			}

			if (file_exists($dir))
			{
				$nblignes = count($object->lines);

				// Create pdf instance
				$pdf=pdf_getInstance($this->format);
                $default_font_size = pdf_getPDFFontSize($outputlangs);	// Must be after pdf_getInstance
				$heightforinfotot = 50;	// Height reserved to output the info and total part
		        $heightforfreetext= (isset($conf->global->MAIN_PDF_FREETEXT_HEIGHT)?$conf->global->MAIN_PDF_FREETEXT_HEIGHT:5);	// Height reserved to output the free text on last page
	            $heightforfooter = $this->marge_basse + 8;	// Height reserved to output the footer (value include bottom margin)
                $pdf->SetAutoPageBreak(1,0);

                if (class_exists('TCPDF'))
                {
                    $pdf->setPrintHeader(false);
                    $pdf->setPrintFooter(false);
                }
                $pdf->SetFont(pdf_getPDFFont($outputlangs));
                // Set path to the background PDF File
                if (empty($conf->global->MAIN_DISABLE_FPDI) && ! empty($conf->global->MAIN_ADD_PDF_BACKGROUND))
                {
                    $pagecount = $pdf->setSourceFile($conf->mycompany->dir_output.'/'.$conf->global->MAIN_ADD_PDF_BACKGROUND);
                    $tplidx = $pdf->importPage(1);
                }

				$pdf->Open();
				$pagenb=0;
				$pdf->SetDrawColor(128,128,128);

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("Repair"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("Repair"));
				if (! empty($conf->global->MAIN_DISABLE_PDF_COMPRESSION)) $pdf->SetCompression(false);

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);   // Left, Top, Right

				// Positionne $this->atleastonediscount si on a au moins une remise
				for ($i = 0 ; $i < $nblignes ; $i++)
				{
					if ($object->lines[$i]->remise_percent)
					{
						$this->atleastonediscount++;
					}
				}

				// New page 
				$pdf->AddPage("L");
				if (! empty($tplidx)) $pdf->useTemplate($tplidx);
				$pagenb++;
				$this->_pagehead($pdf, $object, 1, $outputlangs, $hookmanager);
/*				$pdf->SetFont('','', $default_font_size - 1);
				$pdf->MultiCell(0, 3, '');		// Set interline to 3
				$pdf->SetTextColor(0,0,0);


				$tab_top = 90;
				$tab_top_newpage = (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)?42:10);
				$tab_height = 130;
				$tab_height_newpage = 150;

				// Affiche notes
				if (! empty($object->note_public))
				{
					$tab_top = 88;

					$pdf->SetFont('','', $default_font_size - 1);
					$pdf->writeHTMLCell(190, 3, $this->posxdesc-1, $tab_top, dol_htmlentitiesbr($object->note_public), 0, 1);
					$nexY = $pdf->GetY();
					$height_note=$nexY-$tab_top;

					// Rect prend une longueur en 3eme param
					$pdf->SetDrawColor(192,192,192);
					$pdf->Rect($this->marge_gauche, $tab_top-1, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $height_note+1);

					$tab_height = $tab_height - $height_note;
					$tab_top = $nexY+6;
				}
				else
				{
					$height_note=0;
				}

				$iniY = $tab_top + 7;
				$curY = $tab_top + 7;
				$nexY = $tab_top + 7;
*//*
				// Loop on each lines
				for ($i = 0 ; $i < $nblignes ; $i++)
				{
					$curY = $nexY;
					$pdf->SetFont('','', $default_font_size - 1);   // Into loop to work with multipage
					$pdf->SetTextColor(0,0,0);

					$pdf->setTopMargin($tab_top_newpage);
					$pdf->setPageOrientation('', 1, $heightforfooter+$heightforfreetext+$heightforinfotot);	// The only function to edit the bottom margin of current page to set it.
					$pageposbefore=$pdf->getPage();

					// Description of product line
					$curX = $this->posxdesc-1;

					$showpricebeforepagebreak=1;

					$pdf->startTransaction();
					pdf_writelinedesc($pdf,$object,$i,$outputlangs,$this->posxtva-$curX,3,$curX,$curY,$hideref,$hidedesc,0,$hookmanager);
					$pageposafter=$pdf->getPage();
					if ($pageposafter > $pageposbefore)	// There is a pagebreak
					{
						$pdf->rollbackTransaction(true);
						$pageposafter=$pageposbefore;
						//print $pageposafter.'-'.$pageposbefore;exit;
						$pdf->setPageOrientation('', 1, $heightforfooter);	// The only function to edit the bottom margin of current page to set it.
						pdf_writelinedesc($pdf,$object,$i,$outputlangs,$this->posxtva-$curX,4,$curX,$curY,$hideref,$hidedesc,0,$hookmanager);
						$posyafter=$pdf->GetY();
						if ($posyafter > ($this->page_hauteur - ($heightforfooter+$heightforfreetext+$heightforinfotot)))	// There is no space left for total+free text
						{
							if ($i == ($nblignes-1))	// No more lines, and no space left to show total, so we create a new page
							{
								$pdf->AddPage('','',true);
								if (! empty($tplidx)) $pdf->useTemplate($tplidx);
								if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)) $this->_pagehead($pdf, $object, 0, $outputlangs, $hookmanager);
								$pdf->setPage($pagenb+1);
							}
						}
						else
						{
							// We found a page break
							$showpricebeforepagebreak=0;
						}
					}
					else	// No pagebreak
					{
						$pdf->commitTransaction();
					}

					$nexY = $pdf->GetY();
					$pageposafter=$pdf->getPage();
					$pdf->setPage($pageposbefore);
					$pdf->setTopMargin($this->marge_haute);
					$pdf->setPageOrientation('', 1, 0);	// The only function to edit the bottom margin of current page to set it.

					// We suppose that a too long description is moved completely on next page
					if ($pageposafter > $pageposbefore && empty($showpricebeforepagebreak)) {
						$pdf->setPage($pageposafter); $curY = $tab_top_newpage;
					}

					$pdf->SetFont('','',  $default_font_size - 1);   // On repositionne la police par defaut

					// VAT Rate
					if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT))
					{
						$vat_rate = pdf_getlinevatrate($object, $i, $outputlangs, $hidedetails, $hookmanager);
						$pdf->SetXY($this->posxtva, $curY);
						$pdf->MultiCell($this->posxup-$this->posxtva-1, 3, $vat_rate, 0, 'R');
					}

					// Unit price before discount
					$up_excl_tax = pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails, $hookmanager);
					$pdf->SetXY($this->posxup, $curY);
					$pdf->MultiCell($this->posxqty-$this->posxup-1, 3, $up_excl_tax, 0, 'R', 0);

					// Quantity
					$qty = pdf_getlineqty($object, $i, $outputlangs, $hidedetails, $hookmanager);
					$pdf->SetXY($this->posxqty, $curY);
					$pdf->MultiCell($this->posxdiscount-$this->posxqty-1, 3, $qty, 0, 'R');	// Enough for 6 chars

					// Discount on line
					if ($object->lines[$i]->remise_percent)
					{
						$pdf->SetXY($this->posxdiscount-2, $curY);
						$remise_percent = pdf_getlineremisepercent($object, $i, $outputlangs, $hidedetails, $hookmanager);
						$pdf->MultiCell($this->postotalht-$this->posxdiscount+2, 3, $remise_percent, 0, 'R');
					}

					// Total HT line
					$total_excl_tax = pdf_getlinetotalexcltax($object, $i, $outputlangs, $hidedetails, $hookmanager);
					$pdf->SetXY($this->postotalht, $curY);
					$pdf->MultiCell($this->page_largeur-$this->marge_droite-$this->postotalht, 3, $total_excl_tax, 0, 'R', 0);

					// Collecte des totaux par valeur de tva dans $this->tva["taux"]=total_tva
					$tvaligne=$object->lines[$i]->total_tva;

					$localtax1ligne=$object->lines[$i]->total_localtax1;
					$localtax2ligne=$object->lines[$i]->total_localtax2;

					if ($object->remise_percent) $tvaligne-=($tvaligne*$object->remise_percent)/100;
					if ($object->remise_percent) $localtax1ligne-=($localtax1ligne*$object->remise_percent)/100;
					if ($object->remise_percent) $localtax2ligne-=($localtax2ligne*$object->remise_percent)/100;

					$vatrate=(string) $object->lines[$i]->tva_tx;
					$localtax1rate=(string) $object->lines[$i]->localtax1_tx;
					$localtax2rate=(string) $object->lines[$i]->localtax2_tx;
						
					if (($object->lines[$i]->info_bits & 0x01) == 0x01) $vatrate.='*';
					if (! isset($this->tva[$vatrate])) 				$this->tva[$vatrate]='';
					if (! isset($this->localtax1[$localtax1rate])) 	$this->localtax1[$localtax1rate]='';
					if (! isset($this->localtax2[$localtax2rate])) 	$this->localtax2[$localtax2rate]='';
					$this->tva[$vatrate] += $tvaligne;
					$this->localtax1[$localtax1rate]+=$localtax1ligne;
					$this->localtax2[$localtax2rate]+=$localtax2ligne;
					
					// Add line
					if (! empty($conf->global->MAIN_PDF_DASH_BETWEEN_LINES) && $i < ($nblignes - 1))
					{
						$pdf->SetLineStyle(array('dash'=>'1,1','color'=>array(210,210,210)));
						//$pdf->SetDrawColor(190,190,200);
						$pdf->line($this->marge_gauche, $nexY+1, $this->page_largeur - $this->marge_droite, $nexY+1);
						$pdf->SetLineStyle(array('dash'=>0));
					}

					$nexY+=2;    // Passe espace entre les lignes

					// Detect if some page were added automatically and output _tableau for past pages
					while ($pagenb < $pageposafter)
					{
						$pdf->setPage($pagenb);
						if ($pagenb == 1)
						{
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1);
						}
						else
						{
							$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1);
						}
						$this->_pagefoot($pdf,$object,$outputlangs,1);
						$pagenb++;
						$pdf->setPage($pagenb);
						$pdf->setPageOrientation('', 1, 0);	// The only function to edit the bottom margin of current page to set it.
						if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)) $this->_pagehead($pdf, $object, 0, $outputlangs, $hookmanager);
					}
					if (isset($object->lines[$i+1]->pagebreak) && $object->lines[$i+1]->pagebreak)
					{
						if ($pagenb == 1)
						{
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1);
						}
						else
						{
							$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1);
						}
						$this->_pagefoot($pdf,$object,$outputlangs,1);
						// New page
						$pdf->AddPage();
						if (! empty($tplidx)) $pdf->useTemplate($tplidx);
						$pagenb++;
						if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)) $this->_pagehead($pdf, $object, 0, $outputlangs, $hookmanager);
					}
				}
*/
				// Show square
				if ($pagenb == 1)
				{
					$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 0, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;
				}
				else
				{
					$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 1, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;
				}

				// Affiche zone infos
				$posy=$this->_tableau_info($pdf, $object, $bottomlasttab, $outputlangs);

				// Affiche zone totaux
				$posy=$this->_tableau_tot($pdf, $object, $deja_regle, $bottomlasttab, $outputlangs);

				// Affiche zone versements
				if ($deja_regle)
				{
					$posy=$this->_tableau_versements($pdf, $object, $posy, $outputlangs);
				}

				// Pied de page
				$this->_pagefoot($pdf,$object,$outputlangs);
				$pdf->AliasNbPages();

				$pdf->Close();

				$pdf->Output($file,'F');

				// Add pdfgeneration hook
				if (! is_object($hookmanager))
				{
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('afterPDFCreation',$parameters,$this,$action);    // Note that $action and $object may have been modified by some hooks

				if (! empty($conf->global->MAIN_UMASK))
					@chmod($file, octdec($conf->global->MAIN_UMASK));

				return 1;   // Pas d'erreur
			}
			else
			{
				$this->error=$langs->trans("ErrorCanNotCreateDir",$dir);
				return 0;
			}
		}
		else
		{
			$this->error=$langs->trans("ErrorConstantNotDefined","REPAIR_OUTPUTDIR");
			return 0;
		}
		$this->error=$langs->trans("ErrorUnknown");
		return 0;   // Erreur par defaut
	}

	/**
	 *  Show payments table
     *
	 *  @param	PDF			&$pdf     		Object PDF
	 *  @param  Object		$object			Object order
	 *	@param	int			$posy			Position y in PDF
	 *	@param	Translate	$outputlangs	Object langs for output
	 *	@return int							<0 if KO, >0 if OK
	 */
	function _tableau_versements(&$pdf, $object, $posy, $outputlangs)
	{

	}


	/**
	 *   Show miscellaneous information (payment mode, payment term, ...)
	 *
	 *   @param		PDF			&$pdf     		Object PDF
	 *   @param		Object		$object			Object to show
	 *   @param		int			$posy			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @return	void
	 */
	function _tableau_info(&$pdf, $object, $posy, $outputlangs)
	{
/*		global $conf;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$pdf->SetFont('','', $default_font_size - 1);

        // If France, show VAT mention if not applicable
		if ($this->emetteur->pays_code == 'FR' && $this->franchise == 1)
		{
			$pdf->SetFont('','B', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("VATIsNotUsedForInvoice"), 0, 'L', 0);

			$posy=$pdf->GetY()+4;
		}

		$posxval=52;

		// Show payments conditions
		if ($object->cond_reglement_code || $object->cond_reglement)
		{
			$pdf->SetFont('','B', $default_font_size - 2);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("PaymentConditions").':';
			$pdf->MultiCell(80, 4, $titre, 0, 'L');

			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posxval, $posy);
			$lib_condition_paiement=$outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code)!=('PaymentCondition'.$object->cond_reglement_code)?$outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code):$outputlangs->convToOutputCharset($object->cond_reglement_doc);
			$lib_condition_paiement=str_replace('\n',"\n",$lib_condition_paiement);
			$pdf->MultiCell(80, 4, $lib_condition_paiement,0,'L');

			$posy=$pdf->GetY()+3;
		}
*/
        // Check a payment mode is defined
        /* Not used with orders
		if (empty($object->mode_reglement_code)
        	&& ! $conf->global->FACTURE_CHQ_NUMBER
        	&& ! $conf->global->FACTURE_RIB_NUMBER)
		{
            $pdf->SetXY($this->marge_gauche, $posy);
            $pdf->SetTextColor(200,0,0);
            $pdf->SetFont('','B', $default_font_size - 2);
            $pdf->MultiCell(80, 3, $outputlangs->transnoentities("ErrorNoPaiementModeConfigured"),0,'L',0);
            $pdf->SetTextColor(0,0,0);

            $posy=$pdf->GetY()+1;
        }
		*/
/*
      	// Show payment mode
        if ($object->mode_reglement_code
        	 && $object->mode_reglement_code != 'CHQ'
           	 && $object->mode_reglement_code != 'VIR')
           	 {
	            $pdf->SetFont('','B', $default_font_size - 2);
	            $pdf->SetXY($this->marge_gauche, $posy);
	            $titre = $outputlangs->transnoentities("PaymentMode").':';
	            $pdf->MultiCell(80, 5, $titre, 0, 'L');

				$pdf->SetFont('','', $default_font_size - 2);
	            $pdf->SetXY($posxval, $posy);
	            $lib_mode_reg=$outputlangs->transnoentities("PaymentType".$object->mode_reglement_code)!=('PaymentType'.$object->mode_reglement_code)?$outputlangs->transnoentities("PaymentType".$object->mode_reglement_code):$outputlangs->convToOutputCharset($object->mode_reglement);
	            $pdf->MultiCell(80, 5, $lib_mode_reg,0,'L');

	            $posy=$pdf->GetY()+2;
           	 }

		// Show payment mode CHQ
        if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'CHQ')
        {
        	// Si mode reglement non force ou si force a CHQ
	        if (! empty($conf->global->FACTURE_CHQ_NUMBER))
	        {
	            if ($conf->global->FACTURE_CHQ_NUMBER > 0)
	            {
	                $account = new Account($this->db);
	                $account->fetch($conf->global->FACTURE_CHQ_NUMBER);

	                $pdf->SetXY($this->marge_gauche, $posy);
	                $pdf->SetFont('','B', $default_font_size - 3);
	                $pdf->MultiCell(100, 3, $outputlangs->transnoentities('PaymentByChequeOrderedTo',$account->proprio),0,'L',0);
		            $posy=$pdf->GetY()+1;

		            if (empty($conf->global->MAIN_PDF_HIDE_CHQ_ADDRESS))
		            {
		                $pdf->SetXY($this->marge_gauche, $posy);
		                $pdf->SetFont('','', $default_font_size - 3);
		                $pdf->MultiCell(100, 3, $outputlangs->convToOutputCharset($account->adresse_proprio), 0, 'L', 0);
			            $posy=$pdf->GetY()+2;
		            }
	            }
	            if ($conf->global->FACTURE_CHQ_NUMBER == -1)
	            {
	                $pdf->SetXY($this->marge_gauche, $posy);
	                $pdf->SetFont('','B', $default_font_size - 3);
	                $pdf->MultiCell(100, 3, $outputlangs->transnoentities('PaymentByChequeOrderedTo',$this->emetteur->name),0,'L',0);
		            $posy=$pdf->GetY()+1;

		            if (empty($conf->global->MAIN_PDF_HIDE_CHQ_ADDRESS))
		            {
			            $pdf->SetXY($this->marge_gauche, $posy);
		                $pdf->SetFont('','', $default_font_size - 3);
		                $pdf->MultiCell(100, 3, $outputlangs->convToOutputCharset($this->emetteur->getFullAddress()), 0, 'L', 0);
			            $posy=$pdf->GetY()+2;
		            }
	            }
	        }
		}

        // If payment mode not forced or forced to VIR, show payment with BAN
        if (empty($object->mode_reglement_code) || $object->mode_reglement_code == 'VIR')
        {
	        if (! empty($conf->global->FACTURE_RIB_NUMBER))
	        {
                $account = new Account($this->db);
                $account->fetch($conf->global->FACTURE_RIB_NUMBER);

                $curx=$this->marge_gauche;
                $cury=$posy;

                $posy=pdf_bank($pdf,$outputlangs,$curx,$cury,$account,0,$default_font_size);

                $posy+=2;
	        }
		}

		return $posy;
*/
	}


	/**
	 *	Show total to pay
	 *
	 *	@param	PDF			&$pdf           Object PDF
	 *	@param  Facture		$object         Object invoice
	 *	@param  int			$deja_regle     Montant deja regle
	 *	@param	int			$posy			Position depart
	 *	@param	Translate	$outputlangs	Objet langs
	 *	@return int							Position pour suite
	 */
	function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs)
	{
/*	    global $conf,$mysoc;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$tab2_top = $posy;
		$tab2_hl = 4;
		$pdf->SetFont('','', $default_font_size - 1);

		// Tableau total
        $col1x = 120; $col2x = 170;
		if ($this->page_largeur < 210) // To work with US executive format
		{
			$col2x-=20;
		}
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder=0;
		$index = 0;

		// Total HT
		$pdf->SetFillColor(255,255,255);
		$pdf->SetXY($col1x, $tab2_top + 0);
		$pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT"), 0, 'L', 1);

		$pdf->SetXY($col2x, $tab2_top + 0);
		$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_ht + (! empty($object->remise)?$object->remise:0)), 0, 'R', 1);

		// Show VAT by rates and total
		$pdf->SetFillColor(248,248,248);

		$this->atleastoneratenotnull=0;
		if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT))
		{
			$tvaisnull=((! empty($this->tva) && count($this->tva) == 1 && isset($this->tva['0.000']) && is_float($this->tva['0.000'])) ? true : false);
			if (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_ISNULL) && $tvaisnull)
			{
				// Nothing to do
			}
			else
			{
							foreach($this->tva as $tvakey => $tvaval)
				{
					if ($tvakey > 0)    // On affiche pas taux 0
					{
						$this->atleastoneratenotnull++;

						$index++;
						$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

						$tvacompl='';
*///						if (preg_match('/\*/',$tvakey))
/*						{
							$tvakey=str_replace('*','',$tvakey);
							$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
						}
						$totalvat =$outputlangs->transnoentities("TotalVAT").' ';
						$totalvat.=vatrate($tvakey,1).$tvacompl;
						$pdf->MultiCell($col2x-$col1x, $tab2_hl, $totalvat, 0, 'L', 1);

						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', 1);
					}
				}
				if (! $this->atleastoneratenotnull) // If no vat at all
				{
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("TotalVAT"), 0, 'L', 1);

					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_tva), 0, 'R', 1);

					// Total LocalTax1
					if (! empty($conf->global->FACTURE_LOCAL_TAX1_OPTION) && $conf->global->FACTURE_LOCAL_TAX1_OPTION=='localtax1on' && $object->total_localtax1>0)
					{
						$index++;
						$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("TotalLT1".$mysoc->country_code), $useborder, 'L', 1);
						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_localtax1), $useborder, 'R', 1);
					}

					// Total LocalTax2
					if (! empty($conf->global->FACTURE_LOCAL_TAX2_OPTION) && $conf->global->FACTURE_LOCAL_TAX2_OPTION=='localtax2on' && $object->total_localtax2>0)
					{
						$index++;
						$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("TotalLT2".$mysoc->country_code), $useborder, 'L', 1);
						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_localtax2), $useborder, 'R', 1);
					}
				}
				else
				{
					if (! empty($conf->global->FACTURE_LOCAL_TAX1_OPTION) && $conf->global->FACTURE_LOCAL_TAX1_OPTION=='localtax1on')
					{
						//Local tax 1
						foreach($this->localtax1 as $tvakey => $tvaval)
						{
							if ($tvakey!=0)    // On affiche pas taux 0
							{
								//$this->atleastoneratenotnull++;

								$index++;
								$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

								$tvacompl='';
*///								if (preg_match('/\*/',$tvakey))
/*								{
									$tvakey=str_replace('*','',$tvakey);
									$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
								}
								$totalvat =$outputlangs->transnoentities("TotalLT1".$mysoc->country_code).' ';
								$totalvat.=vatrate($tvakey,1).$tvacompl;
								$pdf->MultiCell($col2x-$col1x, $tab2_hl, $totalvat, 0, 'L', 1);

								$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
								$pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', 1);
							}
						}
					}

					if (! empty($conf->global->FACTURE_LOCAL_TAX2_OPTION) && $conf->global->FACTURE_LOCAL_TAX2_OPTION=='localtax2on')
					{
						//Local tax 2
						foreach($this->localtax2 as $tvakey => $tvaval)
						{
							if ($tvakey!=0)    // On affiche pas taux 0
							{
								//$this->atleastoneratenotnull++;

								$index++;
								$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

								$tvacompl='';
*///								if (preg_match('/\*/',$tvakey))
/*								{
									$tvakey=str_replace('*','',$tvakey);
									$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
								}
								$totalvat =$outputlangs->transnoentities("TotalLT2".$mysoc->country_code).' ';
								$totalvat.=vatrate($tvakey,1).$tvacompl;
								$pdf->MultiCell($col2x-$col1x, $tab2_hl, $totalvat, 0, 'L', 1);

								$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
								$pdf->MultiCell($largcol2, $tab2_hl, price($tvaval), 0, 'R', 1);
							}
						}
					}
				}

				// Total TTC
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->SetTextColor(0,0,60);
				$pdf->SetFillColor(224,224,224);
				$pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC"), $useborder, 'L', 1);

				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, price($object->total_ttc), $useborder, 'R', 1);
			}
		}

		$pdf->SetTextColor(0,0,0);

        $creditnoteamount=0;
        $depositsamount=0;
		//$creditnoteamount=$object->getSumCreditNotesUsed();
		//$depositsamount=$object->getSumDepositsUsed();
		//print "x".$creditnoteamount."-".$depositsamount;exit;
		$resteapayer = price2num($object->total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
		if (! empty($object->paye)) $resteapayer=0;

		if ($deja_regle > 0)
		{
			// Already paid + Deposits
			$index++;

			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid"), 0, 'L', 0);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($deja_regle), 0, 'R', 0);

			$index++;
			$pdf->SetTextColor(0,0,60);
			$pdf->SetFillColor(224,224,224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x-$col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay"), $useborder, 'L', 1);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($resteapayer), $useborder, 'R', 1);

			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->SetTextColor(0,0,0);
		}

		$index++;
		return ($tab2_top + ($tab2_hl * $index));
*/	}

	/**
	 *   Show table for lines
	 *
	 *   @param		PDF			&$pdf     		Object PDF
	 *   @param		string		$tab_top		Top position of table
	 *   @param		string		$tab_height		Height of table (rectangle)
	 *   @param		int			$nexY			Y (not used)
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param		int			$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
	 *   @param		int			$hidebottom		Hide bottom bar of array
	 *   @return	void
	 */
	function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop=0, $hidebottom=0)
	{
/*		global $conf;

		// Force to disable hidetop and hidebottom
		$hidebottom=0;
		if ($hidetop) $hidetop=-1;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Amount in (at tab_top - 1)
		$pdf->SetTextColor(0,0,0);
		$pdf->SetFont('','', $default_font_size - 2);

		if (empty($hidetop))
		{
			$titre = $outputlangs->transnoentities("AmountInCurrency",$outputlangs->transnoentitiesnoconv("Currency".$conf->currency));
			$pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top-4);
			$pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);
		}

		$pdf->SetDrawColor(128,128,128);
		$pdf->SetFont('','', $default_font_size - 1);

		// Output Rect
		$this->printRect($pdf,$this->marge_gauche, $tab_top, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $tab_height, $hidetop, $hidebottom);	// Rect prend une longueur en 3eme param et 4eme param

		if (empty($hidetop))
		{
			$pdf->line($this->marge_gauche, $tab_top+5, $this->page_largeur-$this->marge_droite, $tab_top+5);	// line prend une position y en 2eme param et 4eme param

			$pdf->SetXY($this->posxdesc-1, $tab_top+1);
			$pdf->MultiCell(108,2, $outputlangs->transnoentities("Designation"),'','L');
		}

		if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT))
		{
			$pdf->line($this->posxtva-1, $tab_top, $this->posxtva-1, $tab_top + $tab_height);
			if (empty($hidetop))
			{
				$pdf->SetXY($this->posxtva-3, $tab_top+1);
				$pdf->MultiCell($this->posxup-$this->posxtva+3,2, $outputlangs->transnoentities("VAT"),'','C');
			}
		}

		$pdf->line($this->posxup-1, $tab_top, $this->posxup-1, $tab_top + $tab_height);
		if (empty($hidetop))
		{
			$pdf->SetXY($this->posxup-1, $tab_top+1);
			$pdf->MultiCell($this->posxqty-$this->posxup-1,2, $outputlangs->transnoentities("PriceUHT"),'','C');
		}

		$pdf->line($this->posxqty-1, $tab_top, $this->posxqty-1, $tab_top + $tab_height);
		if (empty($hidetop))
		{
			$pdf->SetXY($this->posxqty-1, $tab_top+1);
			$pdf->MultiCell($this->posxdiscount-$this->posxqty-1,2, $outputlangs->transnoentities("Qty"),'','C');
		}

		$pdf->line($this->posxdiscount-1, $tab_top, $this->posxdiscount-1, $tab_top + $tab_height);
		if (empty($hidetop))
		{
			if ($this->atleastonediscount)
			{
				$pdf->SetXY($this->posxdiscount-1, $tab_top+1);
				$pdf->MultiCell($this->postotalht-$this->posxdiscount+1,2, $outputlangs->transnoentities("ReductionShort"),'','C');
			}
		}

		if ($this->atleastonediscount)
		{
			$pdf->line($this->postotalht, $tab_top, $this->postotalht, $tab_top + $tab_height);
		}
		if (empty($hidetop))
		{
			$pdf->SetXY($this->postotalht-1, $tab_top+1);
			$pdf->MultiCell(30,2, $outputlangs->transnoentities("TotalHT"),'','C');
		}
*/	}

	/**
	 *  Show top header of page.
	 *
	 *  @param	PDF			&$pdf     		Object PDF
	 *  @param  Object		$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @param	object		$hookmanager	Hookmanager object
	 *  @return	void
	 */
	function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $hookmanager)
	{
		global $conf,$langs;

		$outputlangs->load("main");
		$outputlangs->load("bills");
		$outputlangs->load("propal");
		$outputlangs->load("companies");
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf,$outputlangs,$this->page_hauteur);
		$cadre=0;
		$larCelTitre=25; 
		$largeur = $this->page_largeur - ($this->marge_droite + $this->marge_gauche);
		$gauche = $this->marge_gauche;
		$droite =  $this->page_largeur - $this->marge_droite;


		// Show Watermark
		if($object->support_id != "Mag" /*&& (! empty($conf->global->REPAIR_CARD_WATERMARK))*/ )
		{
			$watermark = $object->getSupportLabel($object->support_id);
            pdf_watermark($pdf,$outputlangs,$this->page_hauteur,$this->page_largeur,'mm',$watermark);
		}

//		$pdf->SetTextColor(0,0,60);
//		$pdf->SetFont('','B', $default_font_size + 3);


//		$posx=$this->page_largeur-$this->marge_droite-100;




//		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,0);
		$title=$outputlangs->transnoentities("Repair");

		$pdf->SetFont('','', $default_font_size );

		$posx=$gauche;
		$posy=$this->marge_haute;
		//societe
		$pdf->SetXY($posx,$posy);
		$pdf->SetFont('','', $default_font_size - 2);
		$pdf->cell($largeur, 5, $this->emetteur->name." - ".$this->emetteur->phone." - ".$this->emetteur->zip." ".$this->emetteur->town,$cadre,0,'C');
		$pdf->SetFont('','', $default_font_size);

		$posx=$gauche;
		$posy+=7;
		//Date
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre-10,5, $outputlangs->transnoentities('Date')." : ",$cadre,0,'L');
		$posx = $pdf->GetX();
		$pdf->cell(($largeur/3) - $posx,5, dol_print_date($object->date,"%d %b %Y",false,$outputlangs,true),$cadre,0,"L");
	
		//Ref
//		$pdf->SetXY($this->page_largeur-$this->marge_droite - 80,$posy);
		$pdf->cell( $larCelTitre-10,5, $outputlangs->transnoentities("Ref")." : ",$cadre,0,"L");
		$posx = $pdf->GetX();
		$pdf->cell((2*($largeur/3)) - $posx,5, $outputlangs->convToOutputCharset($object->ref),$cadre,0,"L");

		//Date de livraison
//		$pdf->SetXY($this->page_largeur-$this->marge_droite - 80,$posy);
		$pdf->cell( 40,5, $outputlangs->transnoentities("DateDeliveryPlanned")." : ",$cadre,0,"L");
		$posx = $pdf->GetX();
		$pdf->cell($droite - $posx,5, dol_print_date($object->date_livraison,"%d %b %Y",false,$outputlangs,true),$cadre,0,"L");

########################################################################################
		$posx=$gauche;
		$posy+=8;

		//Nom
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Name")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->SetFont('','B', $default_font_size + 2);
		$pdf->cell($largeur - 2 * $posx, 5, $outputlangs->convToOutputCharset($object->client->nom), $cadre, 0, 'L');		
		$pdf->SetFont('','', $default_font_size	);

		$posx=$gauche;
		$posy+=10;

		//Adresse
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Address")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell($largeur - 2 * $posx, 5, $outputlangs->convToOutputCharset($object->client->address), $cadre, 0, 'L');

###############################################################################
		$posx=$gauche;
		$posy+=10;

		//cp
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Zip")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell(($largeur/2) - $posx, 5, $outputlangs->convToOutputCharset($object->client->zip), $cadre, 0, 'L');

		//Ville
//		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Town")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell($droite - $posx, 5, $outputlangs->convToOutputCharset($object->client->town), $cadre, 0, 'L');


###############################################################################
		$posx=$gauche;
		$posy+=10;

		//Contact
		$usecontact=false;
		$arrayidcontact=$object->getIdContact('external','CUSTOMER');
		if (count($arrayidcontact) > 0)
		{
			$usecontact=true;
			$result=$object->fetch_contact($arrayidcontact[0]);
		}

		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Contacts")." : ", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		if ( $usecontact && (! empty( $object->contact->firstname)))
		$pdf->cell($largeur - 2 * $posx, 5, /*$outputlangs->convToOutputCharset($object->contact->firstname)." ".$outputlangs->convToOutputCharset($object->contact->lastname)*/  $outputlangs->convToOutputCharset($object->contact->getFullName($langs)) , $cadre, 0, 'L');
		else 
			$pdf->cell($largeur - 2 * $posx, 5, "", $cadre, 0, 'L');

		$posx=$gauche;
		$posy+=8;

		//telephone
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Phone")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		if ( $usecontact && (! empty( $object->contact->phone_pro)))
			$pdf->cell(($largeur/2) - $posx, 5, $outputlangs->convToOutputCharset($object->contact->phone_pro), $cadre, 0, 'L');
		else
			$pdf->cell(($largeur/2) - $posx, 5, $outputlangs->convToOutputCharset($object->client->tel), $cadre, 0, 'L');
/*
		$posx=$gauche;
		$posy+=5;
*/		
		//fax
//		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Fax")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		if ( $usecontact && (! empty( $object->contact->fax)))
			$pdf->cell($droit - $posx, 5, $outputlangs->convToOutputCharset($object->contact->fax), $cadre, 0, 'L');
		else
			$pdf->cell($droit - $posx, 5, $outputlangs->convToOutputCharset($object->client->fax), $cadre, 0, 'L');

		$posx=$gauche;
		$posy+=8;
		

		//Portable
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("PhoneMobile")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		if ( $usecontact && (! empty( $object->contact->phone_mobile)))
			$pdf->cell(($largeur/2) - $posx, 5, $outputlangs->convToOutputCharset($object->contact->phone_mobile), $cadre, 0, 'L');
		else 
			$pdf->cell($droit - $posx, 5, "", $cadre, 0, 'L');
		
		//couriel
		$pdf->SetXY($largeur/2,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Email")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		if ( $usecontact && (! empty( $object->contact->email)))
			$pdf->cell($droit - $posx, 5, $outputlangs->convToOutputCharset($object->contact->email), $cadre, 0, 'L');
		else 
			$pdf->cell($droit - $posx, 5, $outputlangs->convToOutputCharset($object->client->email), $cadre, 0, 'L');

################################################################################################
		$posx=$gauche;
		$posy+=7;

		$pdf->line(($largeur/2)-40,$posy,($largeur/2)+40,$posy);
################################################################################################
		$posx=$gauche;
		$posy+=2;

		//marque 
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("MachineTrademark")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell(($largeur/2) - $posx, 5, $outputlangs->convToOutputCharset($object->trademark), $cadre, 0, 'L');
/*
		$posy+=5;
		$posx=$gauche;
*/
		//Modele 
//		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("MachineModel")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell($droite - $posx, 5, $outputlangs->convToOutputCharset($object->model), $cadre, 0, 'L');


		$posx=$gauche;
		$posy+=10;

		//N° Serie 
		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("MachineNSerie")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell(($largeur/2) - $posx, 5, $outputlangs->convToOutputCharset($object->serial_num), $cadre, 0, 'L');

/*
		$posx=$gauche;
		$posy+=5;
*/
		//Ref Client
//		$pdf->SetXY($posx,$posy);
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("RefCustomer")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell($droite - $posx, 5, $outputlangs->convToOutputCharset($object->ref_client), $cadre, 0, 'L');

		$posx=$gauche + $larCelTitre;
		$posy+=10;

		//Accessory
		$pdf->SetXY($posx,$posy);
		$pdf->multiCell($largeur - $posx, 5, $outputlangs->convToOutputCharset($object->accessory), $cadre, 'L', 0);
		$posy_fin = $pdf->GetY();
		$pdf->SetXY($gauche, $posy + (($posy_fin - $posy) / 2) - 2.5 );
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("RepairAccessory")." :", $cadre, 0, 'L');
################################################################################################
		$posx = $gauche;
		$posy = $posy_fin + 2;

		$pdf->line(($largeur/2)-40,$posy,($largeur/2)+40,$posy);
################################################################################################
		$posx=$gauche + $larCelTitre;
		$posy+=2;

		//Panne 
/*		$pdf->SetXY($posx,$posy);
		$pdf->cell(20, 5, $outputlangs->transnoentities("RepairBreakdown")." :", $cadre, 0, 'L');
		$posx = $pdf->GetX();
		$pdf->cell($droite - $posx, 5, $outputlangs->convToOutputCharset($object->breakdown), $cadre, 0, 'C');
*/
		$pdf->SetXY($posx,$posy);
		$pdf->multiCell($largeur - $posx, 5, $outputlangs->convToOutputCharset($object->breakdown), $cadre, 'L', 0);
//		$posx = $pdf->GetX();
		$posy_fin = $pdf->GetY();
		$pdf->SetXY($gauche, $posy + (($posy_fin - $posy) / 2) - 2.5 );
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("RepairBreakdown")." :", $cadre, 0, 'L');

		$posx=$gauche + $larCelTitre;
		$posy =$posy_fin + 5;

		//Note 
		$pdf->SetXY($posx,$posy);
		$pdf->multiCell($largeur - $posx, 5, $outputlangs->convToOutputCharset($object->note_public), $cadre, 'L', 0);
//		$posx = $pdf->GetX();
		$posy_fin = $pdf->GetY();
		$pdf->SetXY($gauche, $posy + (($posy_fin - $posy) / 2) - 2.5 );
		$pdf->cell($larCelTitre, 5, $outputlangs->transnoentities("Notes")." :", $cadre, 0, 'L');



		

		
/*
		$posy+=1;
		$pdf->SetFont('','', $default_font_size - 1);

		if ($object->ref_client)
		{
			$posy+=5;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("RefCustomer")." : " . $outputlangs->convToOutputCharset($object->ref_client), '', 'R');
		}

		$posy+=4;
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("RepairDate")." : " . dol_print_date($object->date,"%d %b %Y",false,$outputlangs,true), '', 'R');

		$posy+=2;

		// Show list of linked objects
		$posy = pdf_writeLinkedObjects($pdf, $object, $outputlangs, $posx, $posy, 100, 3, 'R', $default_font_size, $hookmanager);

		if ($showaddress)
		{
			// Sender properties
			$carac_emetteur = pdf_build_address($outputlangs,$this->emetteur);

			// Show sender
			$posy=42;
			$posx=$this->marge_gauche;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->page_largeur-$this->marge_droite-80;
			$hautcadre=40;

			// Show sender frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx,$posy-5);
			$pdf->MultiCell(66,5, $outputlangs->transnoentities("BillFrom").":", 0, 'L');
			$pdf->SetXY($posx,$posy);
			$pdf->SetFillColor(230,230,230);
			$pdf->MultiCell(82, $hautcadre, "", 0, 'R', 1);
			$pdf->SetTextColor(0,0,60);

			// Show sender name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B', $default_font_size);
			$pdf->MultiCell(80, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');

			// Show sender information
			$pdf->SetXY($posx+2,$posy+8);
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->MultiCell(80, 4, $carac_emetteur, 0, 'L');



			// If CUSTOMER contact defined on order, we use it
			$usecontact=false;
			$arrayidcontact=$object->getIdContact('external','CUSTOMER');
			if (count($arrayidcontact) > 0)
			{
				$usecontact=true;
				$result=$object->fetch_contact($arrayidcontact[0]);
			}

			// Recipient name
			if (! empty($usecontact))
			{
				// On peut utiliser le nom de la societe du contact
				if (! empty($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT)) $socname = $object->contact->socname;
				else $socname = $object->client->nom;
				$carac_client_name=$outputlangs->convToOutputCharset($socname);
			}
			else
			{
				$carac_client_name=$outputlangs->convToOutputCharset($object->client->nom);
			}

			$carac_client=pdf_build_address($outputlangs,$this->emetteur,$object->client,($usecontact?$object->contact:''),$usecontact,'target');

			// Show recipient
			$widthrecbox=100;
			if ($this->page_largeur < 210) $widthrecbox=84;	// To work with US executive format
			$posy=42;
			$posx=$this->page_largeur-$this->marge_droite-$widthrecbox;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->marge_gauche;

			// Show recipient frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx+2,$posy-5);
			$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo").":",0,'L');
			$pdf->Rect($posx, $posy, $widthrecbox, $hautcadre);

			// Show recipient name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B', $default_font_size);
			$pdf->MultiCell($widthrecbox, 4, $carac_client_name, 0, 'L');

			// Show recipient information
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->SetXY($posx+2,$posy+4+(dol_nboflines_bis($carac_client_name,50)*4));
			$pdf->MultiCell($widthrecbox, 4, $carac_client, 0, 'L');
		}
*/	}

	/**
	 *   	Show footer of page. Need this->emetteur object
     *
	 *   	@param	PDF			&$pdf     			PDF
	 * 		@param	Object		$object				Object to show
	 *      @param	Translate	$outputlangs		Object lang for output
	 *      @param	int			$hidefreetext		1=Hide free text
	 *      @return	int								Return height of bottom margin including footer text
	 */
	function _pagefoot(&$pdf,$object,$outputlangs,$hidefreetext=0)
	{
//		return pdf_pagefoot($pdf,$outputlangs,'REPAIR_FREE_TEXT',$this->emetteur,$this->marge_basse,$this->marge_gauche,$this->page_hauteur,$object,0,$hidefreetext);
	}

}

?>