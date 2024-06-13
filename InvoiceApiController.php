<?php

namespace App\Http\Controllers\Api;

use Validator;
use Storage;
use Auth;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use App\Models\Invoice;
use App\Models\InvoiceJPK;
use App\Models\JPKSlownik;
use App\Models\JPKSlownikElem;
use App\Models\OwnInvoice;
use App\Models\InvoiceChatMessage;
use App\InvoiceStatus;
use App\PochodzenieDokumentu;
use App\JPKRodzaj;
use ViewHelper;
use App\RodzajLicencji;
use App\Contracts\InvoiceService;
use App\Contracts\WalutyService;
use App\Models\SlownikiRejestru;
use App\Models\Customer;
use App\Models\InvoiceDokumenty;
use App\Models\CustomersCurrencie;

use Exception;

class InvoiceApiController extends Controller
{
    private $service;
    private $serviceWaluty;

    public function __construct(InvoiceService $service, WalutyService $serviceWaluty)
    {
        $this->service = $service;
        $this->serviceWaluty = $serviceWaluty;
    }

    // public function delete()
    // {
    //     $userInfo = Auth::user();
    //     if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
    //     {
    //         return [];
    //     }
    //     logger()->debug('ROZPOCZETO usuwanie dokumentow zakupu/sprzedazy');

    //     try
    //     {
    //         $invoices = Invoice::where('pochodzenieDokumentu', PochodzenieDokumentu::Kancelaria)->orWhere('pochodzenieDokumentu', PochodzenieDokumentu::Optima)->delete();
    //     }
    //     catch (Exception $e)
    //     {
    //         logger()->error($e->getMessage());
    //     }

    //     return '';
    // }

    protected function normalizeFilename($filename)
    {
        $arr = explode('.', $filename);
        $ext = count($arr) > 0 ? last($arr) : '';
        $name = str_replace('.'.$ext, '', $filename);
        $name = str_slug($name, '_').'.'.$ext;
        return strtolower($name);
    }

    public function deleteAttachmentInvoice(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        logger()->debug('InvoiceApiController->deleteAttachmentInvoice');
        $result = ['deleted' => [], 'errors' => []];
        $dane = $request->input('data');

        // logger()->debug('InvoiceApiController->deleteAttachmentInvoice: '.print_r($dane, true));

        foreach ($dane as $key => $zalacznik)
        {
            $dokument = InvoiceDokumenty::where('id', $zalacznik['eszok_id'])->first();

            if($dokument)
            {
                $dokument->delete();
                $backupDodatkowe = $this->service->backupDodatkoweDokumenty($dokument);

                $invoice = Invoice::find($dokument->invoice_id);

                $invoice->dodatkowy_dokument_id_del = $backupDodatkowe->id;

                $invoice->save();

                $this->service->backupInvoice($invoice);
            }

            array_push($result['deleted'], $zalacznik['kancelaria_id'] . '|' . $zalacznik['db_name']. '|'.$zalacznik['eszok_id']);
        }

        return $result;
    }

    public function pobierzNoweWiadomosciFakturDlaKancelarii(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }
        $dane = $request->all();
        logger()->debug('InvoiceApiController->pobierzNoweWiadomosciFakturDlaKancelarii: '.print_r($dane, true));

        $result = ['created' => [], 'errors' => []];
        try
        {   
            if(!isset($dane['dataOstatniegoSprawdzenia']))
            {
                throw new Exception("Brak parametru: dataOstatniegoSprawdzenia");
            }

            $listaNowychWiadomosci = InvoiceChatMessage::where('invoice_id', '>', 0)->where('created_at', '>', $dane['dataOstatniegoSprawdzenia'])->get();
            foreach($listaNowychWiadomosci as $wiadomosc)
            {
                array_push($result['created'], [
                    'eszok_id_wiadomosci' => $wiadomosc->id,
                    'eszok_id_dokumentu' => $wiadomosc->invoice_id,
                    'autor' => $wiadomosc->author,
                    'wiadomosc' => $wiadomosc->content,
                    'created_at' => $wiadomosc->created_at,
                ]);
            }
        }
        catch (Exception $e)
        {
            logger()->error('InvoiceApiController->pobierzNoweWiadomosciFakturDlaKancelarii: '.$e->getMessage());
            array_push($result['errors'], ['key' => 'INFO', 'value' => $e->getMessage()]);
        }
        logger()->debug('InvoiceApiController->pobierzNoweWiadomosciFakturDlaKancelarii result: '.print_r($result, true));
        return $result;
    }
    public function createNewAttachmentInvoice(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        logger()->debug('InvoiceApiController->createNewAttachmentInvoice');
        $result = ['created' => [], 'errors' => []];
        $dane = $request->input('data');

        $user = Auth::user();

        // logger()->debug('InvoiceApiController->createNewAttachmentInvoice: '.print_r($dane, true));

        foreach ($dane as $key => $zalacznik)
        {
            try
            {

                if (!isset($zalacznik['db_name']))
                    throw new Exception("Brak db_name");

                $customer = Customer::where('db_name', $zalacznik['db_name'])->first();
                if ($customer == null)
                    throw new Exception("Nie znaleziono klienta");

                $filename = $this->normalizeFilename($zalacznik['filename']);
                //
                $url = 'invoices/dodatkowe-dokumenty/'.$customer->id.'/E!'.$zalacznik['invoice_eszok_id'].'-'.time().'_'.$filename;
                $file = base64_decode($zalacznik['base64']);
                Storage::put('public/'.$url, $file);

                $currentAttachment = InvoiceDokumenty::create([
                    'path' => $url,
                    'filename' => $zalacznik['filename'],
                    'content_type' => $zalacznik['content_type'],
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                    'invoice_id' => $zalacznik['invoice_eszok_id'],
                    'status' => 1,
                    'pochodzenieDokumentu' => 1,
                    'is_imported' => 1,
                ]);


                array_push($result['created'], $zalacznik['kancelaria_id'] . '|' . $zalacznik['db_name']. '|'.$currentAttachment->id);
            }
            catch (\Exception $e)
            {
                array_push($result['errors'], ['key' => $key, 'value' => $zalacznik['kancelaria_id'] . '|' . $zalacznik['db_name'] . '|' . $e->getMessage()]);

                // logger()->debug('ERROR: InvoiceApiController->createNewAttachmentInvoice: '.print_r(['key' => $key, 'value' => $zalacznik['kancelaria_id'] . '|' . $zalacznik['db_name'] . '|' . $e->getMessage()], true));
            }
        }

        return $result;
    }

    public function dictionary(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }
        
        // logger()->debug('dictionary');
        $result = ['created' => [], 'errors' => []];
        $data = $request->input('data');

        foreach ($data as $i => $dictionary)
        {
            // logger()->debug(print_r($dictionary, true));
            try
            {
                if (!isset($dictionary['db_name']))
                    throw new Exception("Brak db_name");

                $customer = Customer::where('db_name', $dictionary['db_name'])->first();
                if ($customer == null)
                    throw new Exception("Nie znaleziono klienta");


                $new = SlownikiRejestru::where('customer_id', $customer->id)->where('erp_id', $dictionary['erp_id'])->where('type', $dictionary['type'])->first();
                if ($new == null)
                    $new = new SlownikiRejestru();

                $new->erp_id = $dictionary['erp_id'];
                $new->customer_id = $customer->id;
                $new->code = $dictionary['code'];
                $new->name = $dictionary['name'];
                $new->type = $dictionary['type'];
                $new->value = $dictionary['param1'];
                $new->nieaktywny = $dictionary['nieaktywny'];

                $new->save();

                array_push($result['created'], $dictionary['erp_id'] . '|' . $dictionary['db_name']. '|'.$dictionary['type']);



            }
            catch (\Exception $e)
            {
                array_push($result['errors'], ['key' => $i, 'value' => $dictionary['erp_id'] . '|' . $dictionary['db_name'] . '|' . $e->getMessage(). '|'.$dictionary['type']]);
            }


        }
        return $result;
    }

    public function getNewJPKInvoices()
    {
        $result = [];
        logger()->debug('ROZPOCZETO przetwarzanie getNewJPKInvoices()');

        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return $result;
        }

        $listaJPKInvoice = InvoiceJPK::where('is_imported', false)->get();
        foreach($listaJPKInvoice as $invoiceJPK)
        {
            array_push($result, $invoiceJPK->id);
        }

        logger()->debug('ZAKONCZONO przetwarzanie getNewJPKInvoices(): '.print_r($result, true));
        return $result;
    }

    public function passwordProtected(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }
        logger()->debug('ROZPOCZETO przetwarzanie passwordProtected()');
        $dane = $request->all();
        Invoice::whereIn('id', $dane['data'])->update(['status' => InvoiceStatus::FilePassword]);
        logger()->debug('ZAKONCZONO przetwarzanie passwordProtected(): '.print_r($dane, true));
        return [];
    }

    public function getJPKInvoices($id)
    {
        $result = [];

        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return $result;
        }

        $JPKAtrybut = InvoiceJPK::where('id', $id)->first();

        $wartosc = '';
        if($JPKAtrybut->rodzaj_jpk == JPKRodzaj::JPK_FA)
        {
            if($JPKAtrybut->format == 1) $wartosc = is_null($JPKAtrybut->wartosc_tekst) ? '' : $JPKAtrybut->wartosc_tekst;
            if($JPKAtrybut->format == 2) $wartosc = is_null($JPKAtrybut->wartosc_liczba) ? '' : $JPKAtrybut->wartosc_liczba;
            if($JPKAtrybut->format == 3) $wartosc = is_null($JPKAtrybut->wartosc_lista_id) ? '' : $JPKAtrybut->jpkSlownikElem->DAE_Wartosc;
            if($JPKAtrybut->format == 4) $wartosc = is_null($JPKAtrybut->wartosc_data) ? '' : $JPKAtrybut->wartosc_data;
        }
        if($JPKAtrybut->rodzaj_jpk == JPKRodzaj::JPK_V7)
        {
            $wartosc = '';
        }
        if($JPKAtrybut->rodzaj_jpk == JPKRodzaj::JPK_VAT)
        {
            $wartosc = is_null($JPKAtrybut->wartosc_liczba) ? '0' : $JPKAtrybut->wartosc_liczba;
        }

        $result = [
            'JDokID' => $JPKAtrybut->invoice_id,
            'JDokIDOptima' => $JPKAtrybut->invoice->erp_id,
            'JTyp' => $JPKAtrybut->rodzaj_jpk,
            'JOptimaID' => $JPKAtrybut->jpkSlownik->erp_id,
            'JTypGlowny' => $JPKAtrybut->jpkSlownik->DeA_TypGlowny,
            'JWartosc' => $wartosc,
          ];
        // logger()->debug('JPK OWN: '.print_r($result, true));
        return $result;
    }

    public function setAsImportedJPKInvoices(Request $request)
    {
        // logger()->debug('ROZPOCZETO przetwarzanie setAsImportedJPKInvoices(Request $request)');

        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        $data = $request->input('data');

        // logger()->debug('Faktury - odebrano ident. do zatwierdzenia (setAsImportedJPKInvoices)', $data);

        $count = InvoiceJPK::whereIn('id', $data)->update(['is_imported'=>1]);

        // logger()->debug('Faktury - ilość zaktulizowanych wierszy (setAsImportedJPKInvoices)', [$count]);

        $result = InvoiceJPK::whereIn('id', $data)->select('id', 'is_imported')->get();

        // logger()->debug('ZAKONCZONO przetwarzanie setAsImportedJPKInvoices(Request $request)');

        return $result;
    }



    

    public function getNewDodatkoweDokumenty()
    {
        logger()->debug('ROZPOCZETO przetwarzanie getNewDodatkoweDokumenty()');
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        $result = [];

        $newInvoices = InvoiceDokumenty::where('invoice_dokumenty.is_imported', false)
            ->where('invoice_dokumenty.status', 1)->where('invoices.is_imported', 1)
            ->join('invoices', 'invoice_dokumenty.invoice_id', '=', 'invoices.id')
            ->join('customers', 'invoice_dokumenty.customer_id', '=', 'customers.id')->where('customers.db_cfg_id', config('app.idUstawien'))
            ->selectRaw('invoice_dokumenty.id, invoices.is_imported as rejestrZaimportowany')
            ->get();

        foreach($newInvoices as $invoice)
        {
            array_push($result, $invoice->id);
        }

        return $result;
    }

    public function getDodatkoweDokumenty($id)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        $result = [];

        $dodatkoweDokumenty = InvoiceDokumenty::where('id', $id)->first();

        if(!$dodatkoweDokumenty)
        {
            logger()->error('InvoiceApiController@getDodatkoweDokumenty - nie znaleziono dokumentu, id: '.$id);
            return response()->json('Nie znaleziono dokumentu test, id: '.$id, 404);
        }

        if(!Storage::has('public/'.$dodatkoweDokumenty->path))
        {
            logger()->error('InvoiceApiController@getDodatkoweDokumenty - nie znaleziono pliku dokumentu, id: '.$id);

            return response()->json('Nie znaleziono pliku ('.$dodatkoweDokumenty->path.') dokumentu, id: '.$id, 404);
        }

        // logger()->debug(print_r($dodatkoweDokumenty->toArray(), true));

        $file = Storage::get('public/'.$dodatkoweDokumenty->path);

        $result = [
            'db_name' => ($dodatkoweDokumenty->customer_id == 0 ? $dodatkoweDokumenty->invoice->customer->db_name : $dodatkoweDokumenty->customer->db_name),
            'id' => $dodatkoweDokumenty->invoice_id, // id rejestru
            'filename' => $this->buildFileNameDodatkoweDokumenty($dodatkoweDokumenty),
            'data' => base64_encode($file),
            'id_attachment' => $dodatkoweDokumenty->id
          ];

        return $result;
    }

    public function setAsImportedDodatkoweDokumenty(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        $data = $request->input('data');
        $count = InvoiceDokumenty::whereIn('id', $data)->update(['is_imported'=>1]);
        $result = InvoiceDokumenty::whereIn('id', $data)->select('id', 'is_imported')->get();

        return $result;
    }

    public function getNewInvoices()
    {
        logger()->debug('ROZPOCZETO przetwarzanie getNewInvoices()');

        $result = [];
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return $result;
        }

        $newInvoices = Invoice::where('is_imported', false)->whereNull('kancelaria_id')
            ->join('customers', 'invoices.customer_id', '=', 'customers.id')
            ->where(function($query) {
                $query = $query->where('status', InvoiceStatus::Pending);
                $query = $query->orWhere('status', InvoiceStatus::Warning);
            })->where('customers.db_cfg_id', config('app.idUstawien'))
            ->selectRaw('invoices.id, invoices.dodatkowePotwierdzenie, customers.dodatkowePotwierdzenie as wymagajPotwierdzania, customers.db_cfg_id')->limit(1000)
            ->get();

        $licencjaKadrowa = ViewHelper::sprawdzLicencje(RodzajLicencji::Plus);
        foreach($newInvoices as $invoice)
        {
            if($licencjaKadrowa)
            {
                if($invoice->wymagajPotwierdzania == 1 && $invoice->dodatkowePotwierdzenie == 0 && !is_null($invoice->dodatkowePotwierdzenie)) continue;
            }

            array_push($result, $invoice->id);
        }
        return $result;
    }

    public function getInvoice($id)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        logger()->debug('ROZPOCZETO przetwarzanie getInvoice($id)');
        $id = intval($id);

        $invoice = Invoice::find($id);
        if(is_null($invoice))
        {
            logger()->error('InvoiceApiController@getInvoice - nie znaleziono faktury, id: '.$id);
            return response()->json('[getInvoice] Nie znaleziono faktury, id: '.$id, 404);
        }

        if(!Storage::has('public/'.$invoice->path))
        {
            logger()->error('InvoiceApiController@getInvoice - nie znaleziono pliku faktury, id: '.$id);
            return response()->json('Nie znaleziono pliku ('.$invoice->path.') faktury, id: '.$id, 404);
        }

        $file = Storage::get('public/'.$invoice->path);

        $invoice->typVATRejestr = 0;
        $invoice->typEDRejestr = 0;

        if($invoice->typGlownyRejestr != 0)
        {
            if($invoice->slownikTypRejestru->value == 1 || $invoice->slownikTypRejestru->value == 2)
            {
                $invoice->typVATRejestr = $invoice->slownikTypRejestru->erp_id;
            }
            else
            {
                $invoice->typEDRejestr = $invoice->slownikTypRejestru->erp_id;
            }
        }

        $result =  [
            'id' => $id,
            'filename' => $this->buildFileName($invoice),
            'db_name' => $invoice->customer->db_name,
            'created_at' => $invoice->created_at->toDateTimeString(),
            'data' =>  base64_encode($file),
            'DanePliku' => [
                'Kategoria1ErpId' =>  (is_null($invoice->kategoria1) ? NULL : ($invoice->kategoria1Slownik->erp_id ?? NULL)),
                'Kategoria2ErpId' =>  (is_null($invoice->kategoria2) ? NULL : ($invoice->kategoria2Slownik->erp_id ?? NULL)),
                'typVATRejestr' =>  $invoice->typVATRejestr,
                'typEDRejestr' =>  $invoice->typEDRejestr,
                'TypFaktury' =>  $invoice->type,
                'podtyp' =>  $invoice->podtyp,
                'pochodzenieDokumentu' =>  $invoice->pochodzenieDokumentu,
                'ksefReferenceNumberKsef' =>  $invoice->ksefReferenceNumberKsef,
                'ksef_xml_schema' =>  (is_null($invoice->ksef_xml_schema) ? NULL : base64_encode($invoice->ksef_xml_schema)),
                'ksef_schemaVersion' =>  $invoice->ksef_schemaVersion,
                'ksef_wariantFormularza' =>  $invoice->ksef_wariantFormularza,
                'ksefReferenceNumberDemoKsef' =>  $invoice->ksefReferenceNumberDemoKsef,
                'ksef_xml_schema_demo' =>  (is_null($invoice->ksef_xml_schema_demo) ? NULL : base64_encode($invoice->ksef_xml_schema_demo)),
                'ksef_schemaVersion_demo' =>  $invoice->ksef_schemaVersion_demo,
                'ksef_wariantFormularza_demo' =>  $invoice->ksef_wariantFormularza_demo,
            ],
        ];

        logger()->debug('Przetwarzanie getInvoice('.$id.'): '.print_r($result, true));

        logger()->debug('ZAKONCZONO przetwarzanie getInvoice($id)');

        return $result;
    }

    protected function buildFileNameDodatkoweDokumenty($dodtakowyDokument)
    {
        logger()->debug('ROZPOCZETO przetwarzanie buildFileNameDodatkoweDokumenty($dodtakowyDokument)');

        $result =  'O!'.$dodtakowyDokument->invoice_id.'-9-'.$dodtakowyDokument->created_at->timestamp.'-'.$dodtakowyDokument->id.'-'.$dodtakowyDokument->filename;

        logger()->debug('ZAKONCZONO przetwarzanie buildFileNameDodatkoweDokumenty($dodtakowyDokument)');

        return $result;
    }

    protected function buildFileName($invoice)
    {
        logger()->debug('ROZPOCZETO przetwarzanie buildFileName($invoice)');

        $invoice->typVATRejestr = 0;
        $invoice->typEDRejestr = 0;

        if($invoice->typGlownyRejestr != 0)
        {
            if($invoice->slownikTypRejestru->value == 1 || $invoice->slownikTypRejestru->value == 2)
            {
                $invoice->typVATRejestr = $invoice->slownikTypRejestru->erp_id;
            }
            else
            {
                $invoice->typEDRejestr = $invoice->slownikTypRejestru->erp_id;
            }
        }

        $result =  'O!'.$invoice->id.'-'.$invoice->type.'_'.$invoice->typVATRejestr.'_'.$invoice->typEDRejestr.'-'.$invoice->created_at->timestamp.'-'.$invoice->filename;

        logger()->debug('ZAKONCZONO przetwarzanie buildFileName($invoice)');

        return $result;
    }

    public function setAsImported(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        $dane = $request->all();
        logger()->debug('ROZPOCZETO przetwarzanie setAsImported(Request $request): '.print_r($dane, true));

        $invoice = Invoice::where('id', $dane['eszok_id'])->first();
        $invoice->is_imported = 1;
        $invoice->kancelaria_id = $dane['kancelaria_id'];
        $invoice->status = InvoiceStatus::PendingKancelaria;
        $invoice->save();
        $this->service->backupInvoice($invoice);

        logger()->debug('ZAKONCZONO przetwarzanie setAsImported(Request $request)');
        return [];
    }

    public function updateInvoiceStatus(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }

        logger()->debug('ROZPOCZETO przetwarzanie updateInvoiceStatus()');

        $validator = Validator::make($request->all(), [
            'data' => 'required',
            'data.*.type' => 'required',
            'data.*.id' => 'required',
            'data.*.type_doc' => 'required',
            'data.*.podtyp' => 'required',
            'data.*.db_name' => 'required',
            'data.*.operation' => 'required|in:DEL,PRZET,ANUL,DELG'
        ]);

        if($validator->fails()){
            // logger()->debug('Faktury - niepowodzenie walidacji zadania updateInvoiceStatus()', $validator->messages()->toArray());
            return response()->json($validator->messages(), 422);
        }

        $result = [
            'created' => [],
            'updated' => [],
            'errors' => []
        ];

        $user = Auth::user();

        $data = $request->input('data');
        foreach ($data as $req)
        {
            if(!$customerId = Customer::where('db_name', $req['db_name'])->value('id'))
            {
                array_push($result['errors'], ['key' => $req['id'], 'value'=>'Nie znaleziono klienta. db_name: '.$req['db_name'] ]);
                continue;
            }

            if(!$customerId = Customer::where('db_name', $req['db_name'])->value('id'))
            {
                array_push($result['errors'], ['key' => $req['id'], 'value'=>'Nie znaleziono klienta. db_name: '.$req['db_name'] ]);
                continue;
            }

            try
            {
                logger()->info('OP: '.$req['operation'].' TYPE_DOC: '.$req['type_doc'].' ID: '.$req['id'].' podtyp: '.$req['podtyp'].' $customerId: '.$customerId);
                switch ($req['operation'])
                {
                    case 'DEL': // USUWANIE PERNAMENTNE BEZ MOŻLIWOŚCI ODWROTU
                    {
                        if($req['type_doc'] == 0)
                        {
                            // logger()->debug('PATRYK: '.print_r($req, true));
                            if(isset($req['trashType']) && $req['trashType'] == 1)
                            {
                                $invoice = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();
                                if (isset($invoice))
                                {
                                    $invoices = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->delete();

                                    $ownInvoice = OwnInvoice::where('invoice_id', $req['id'])->where('customer_id', $customerId)->first();
                                    $ownInvoice->status = 'CAN';
                                    $ownInvoice->updated_by = $user->id;
                                    $ownInvoice->save();

                                    $this->service->backupInvoice($invoice);
                                }
                            }
                            else if(isset($req['trashType']) && $req['trashType'] == 0)
                            {
                                $invoice = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();
                                if (isset($invoice))
                                {
                                    $invoices = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->forceDelete();

                                    $ownInvoice = OwnInvoice::where('invoice_id', $req['id'])->where('customer_id', $customerId)->first();
                                    $ownInvoice->is_imported = false;
                                    $ownInvoice->status = 'TMP';

                                    $ownInvoice->updated_by = $user->id;
                                    $ownInvoice->save();

                                    $this->service->backupInvoice($invoice);
                                }
                            }
                            else if(isset($req['trashType']) && $req['trashType'] == 2)
                            {
                                $invoice = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();
                                if (isset($invoice))
                                {
                                    $invoices = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->forceDelete();

                                    $ownInvoice = OwnInvoice::where('invoice_id', $req['id'])->where('customer_id', $customerId)->first();
                                    $ownInvoice->is_imported = false;
                                    $ownInvoice->status = 'TMP';

                                    $ownInvoice->updated_by = $user->id;
                                    $ownInvoice->save();

                                    $this->service->backupInvoice($invoice);
                                }
                            }
                        }
                        else
                        {
                            $invoices = Invoice::where('erp_id', $req['id']);

                            if($req['type_doc'] == 4) $invoices = $invoices->where('pochodzenieDokumentu', PochodzenieDokumentu::Optima);

                            $invoices = $invoices->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();

                            if($invoices)
                            {
                                $invoices->delete();
                            }
                        }
                        break;
                    }
                    case 'DELG': // ANULOWANIE DOKUMENTU Z OPCJĄ WYŚWIETLENIA GUZIKA USUŃ DO ARCHIWIZACJI
                    {
                        if($req['type_doc'] == 0 || $req['type_doc'] == 3)
                        {
                            $invoice = Invoice::where('id', $req['id']);
                            if($req['type_doc'] == 0) $invoice = $invoice->where('podtyp', $req['podtyp']);
                            $invoice = $invoice->where('customer_id', $customerId)->first();

                            if($invoice)
                            {
                                $invoice->status = InvoiceStatus::CancelledDel;
                                $invoice->save();

                                $this->service->backupInvoice($invoice);
                            }

                        }
                        else
                        {
                            $invoice = Invoice::where('id', $req['id']);
                            if($req['type_doc'] == 0) $invoice = $invoice->where('podtyp', $req['podtyp']);
                            $invoice = $invoice->where('customer_id', $customerId)->first();

                            if($invoice)
                            {
                                $invoice->status = InvoiceStatus::CancelledDel;
                                $invoice->save();

                                $this->service->backupInvoice($invoice);
                            }
                        }
                        break;
                    }
                    case 'ANUL': // ANULOWANIE DOKUMENTU NADANIE STATUSU ANULOWANY
                    {
                        if($req['type_doc'] == 0 || $req['type_doc'] == 3)
                        {
                            $invoice = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();
                            if($invoice)
                            {
                                $invoice->status = InvoiceStatus::Cancelled;
                                $invoice->save();

                                $this->service->backupInvoice($invoice);
                            }
                        }
                        else
                        {
                            $invoices = Invoice::where('erp_id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();
                            if($invoice)
                            {
                                $invoice->status = InvoiceStatus::Cancelled;
                                $invoice->save();

                                $this->service->backupInvoice($invoice);
                            }
                        }
                        break;
                    }
                    case 'PRZET': // ANULOWANIE DOKUMENTU NADANIE STATUSU ANULOWANY
                    {
                        if($req['type_doc'] == 0)
                        {
                            $invoice = Invoice::where('id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();
                            // $invoice->is_imported = 0;
                            if($invoice)
                            {
                                $invoice->status = InvoiceStatus::Pending;
                                // $invoice->updated_by = $user->id;
                                $invoice->save();

                                $this->service->backupInvoice($invoice);
                            }
                        }
                        else
                        {
                            $invoices = Invoice::where('erp_id', $req['id'])->where('podtyp', $req['podtyp'])->where('customer_id', $customerId)->first();
                            // $invoice->is_imported = 0;
                            if($invoice)
                            {
                                $invoice->status = InvoiceStatus::Pending;
                                // $ownInvoice->updated_by = $user->id;
                                $invoice->save();

                                $this->service->backupInvoice($invoice);
                            }
                        }
                        break;
                    }
                }

                array_push($result['updated'], $req['id'] . '|' .  $req['type_doc']. '|' .  $req['db_name']. '|' .  $req['podtyp']. '|' .  $req['trashType']);
            }
            catch (\Exception $e)
            {
                array_push($result['errors'], $this->makeErrorResult($req['id'], $req['type'], $req['db_name'], $e->getMessage(), $req['podtyp']));
            }

        }

        logger()->debug('ZAKONCZONO przetwarzanie updateInvoiceStatus()');
        return $result;
    }

    private function makeErrorResult($id, $dks_typ, $dbName, $message, $podtyp)
    {
        $key = (string)$id.'|'.(string)$dks_typ.'|'.$dbName.'|'.$podtyp;

        $result = [
            'key' => $key,
            'value' => $message
        ];

        logger()->error('[1]Niepowodzenie walidacji danych faktury: '.$message.' ', $result);

        return $result;
    }

    public function updateOrCreateInvoices(Request $request)
    {
        $userInfo = Auth::user();
        if($userInfo->role != "API" && $userInfo->role != "ADMIN" && $userInfo->role != "OPERATOR")
        {
            return [];
        }
        logger()->debug('ROZPOCZETO przetwarzanie updateOrCreateInvoices(Request $request) - START');

        $result = [
            'created' => [],
            'updated' => [],
            'errors' => []
        ];

        $data = $request->input('data');
        foreach($data as $daneDokumentu)
        {
            try
            {
                $czyDodajJakoNowy = false;
                // logger()->debug('ROZPOCZETO przetwarzanie updateOrCreateInvoices(Request $request): '.print_r($daneDokumentu, true));
                if(!$customer = Customer::where('db_name', $daneDokumentu['base_name'] ?? null)->first())
                {
                    throw new Exception('Nie znaleziono klienta z nazwa bazy: '.$daneDokumentu['base_name'], 1);
                }

                if(isset($daneDokumentu['EszokId']) && !is_null($daneDokumentu['EszokId']) && $daneDokumentu['EszokId'] > 0) 
                {
                    $invoice = Invoice::where('id', $daneDokumentu['EszokId'])->where('customer_id', $customer->id)->withTrashed()->first();
                    if(!$invoice) throw new Exception("Aktualizowany dokument pochodzi z innej bazy niż docelowa", 1);
                }
                else
                {
                    $invoice = Invoice::where('erp_id', $daneDokumentu['OptimaID']);
                    $invoice = $invoice->where('pochodzenieDokumentu', $daneDokumentu['DokPochodzenie']);
                    $invoice = $invoice->where('podtyp', $daneDokumentu['podtyp']);
                    $invoice = $invoice->where('customer_id', $customer->id);
                    $invoice = $invoice->withTrashed()->first();
                }

                if(!$invoice)
                {
                    $invoice = new Invoice();
                    $czyDodajJakoNowy = true;

                    $invoice->pochodzenieDokumentu = $daneDokumentu['DokPochodzenie'];
                    $invoice->customer_id = $customer->id;
                    $invoice->is_imported = true;
                    $invoice->user_id = $userInfo->id;
                }

                $idWaluty = null;
                if($daneDokumentu['currency'] <> "PLN")
                {
                    if(!$idWaluty = CustomersCurrencie::where('symbol', $daneDokumentu['currency'])->where('customer_id', $customer->id)->value('id'))
                    {

                        $kursyWalut = $this->serviceWaluty->pobierzKursyWalut();

                        $istniejeKursWNBP = false;
                        if(isset($kursyWalut[0]['rates']))
                        {
                            foreach($kursyWalut[0]['rates'] as $kurs)
                            {
                                if($kurs['code'] == $daneDokumentu['currency'])
                                {
                                    $nazwaWaluty = ucfirst($kurs['currency']);
                                    $istniejeKursWNBP = true;
                                    break;
                                }
                            }
                        }

                        if($istniejeKursWNBP)
                        {
                            $customers_currencies = CustomersCurrencie::updateOrCreate(
                            ['symbol' => $daneDokumentu['currency'], 'customer_id' => $customer->id],
                            ['symbol' => $daneDokumentu['currency'], 'nazwa' => $nazwaWaluty]);

                            $idWaluty = $customers_currencies->id;
                        }
                        else
                        {
                            throw new Exception('Brak ustawionej waluty '.$daneDokumentu['currency'].' w słownikach klienta '.$daneDokumentu['base_name'], 1);
                        }
                    }
                }

                if($czyDodajJakoNowy && $daneDokumentu['DokPochodzenie'] != PochodzenieDokumentu::Optima)
                {
                    if(!isset($daneDokumentu['data_base64'])) throw new Exception("Brak danych pliku (wydruku: data_base64, baza optima: ".$daneDokumentu['base_name'].")", 1);

                    $filename = str_slug($daneDokumentu['full_number']) . Carbon::now()->format('d_m_Y_H_i_s') . '.' . $daneDokumentu['file_ext'];
                    $path = $this->service->storeFile($customer->id, $filename, $daneDokumentu['data_base64'], $daneDokumentu['type']);

                    $invoice->path = $path;
                    $invoice->filename = $filename;
                }
                
                if(is_null($invoice->ksefReferenceNumberKsef) && !empty($daneDokumentu['KsefNrRef'] ?? ''))
                {
                    $invoice->ksef_xml_schema = base64_decode($daneDokumentu['PlikKsef']);
                    $invoice->ksefReferenceNumberKsef = $daneDokumentu['KsefNrRef'];
                    $invoice->ksef_schemaVersion = $daneDokumentu['KsefSchematPliku'];
                    $invoice->ksef_wariantFormularza = $daneDokumentu['KsefWariantFormularza'];
                }

                if(is_null($invoice->ksefReferenceNumberDemoKsef) && !empty($daneDokumentu['KsefDemoNrRef'] ?? ''))
                {
                    $invoice->ksef_xml_schema_demo =  base64_decode($daneDokumentu['PlikDemoKsef']);
                    $invoice->ksefReferenceNumberDemoKsef = $daneDokumentu['KsefDemoNrRef'];
                    $invoice->ksef_schemaVersion_demo = $daneDokumentu['KsefDemoSchematPliku'];
                    $invoice->ksef_wariantFormularza_demo = $daneDokumentu['KsefDemoWariantFormularza'];
                }             
                
                $invoice->status = InvoiceStatus::Ok;
    
                $invoice->erp_id = $daneDokumentu['OptimaID'];
                $invoice->full_number = $daneDokumentu['full_number'];
                $invoice->issue_date = $daneDokumentu['issue_date'];
                $invoice->type = $daneDokumentu['dok_type'];
                $invoice->contractor_name = $daneDokumentu['contractor_name'];
                $invoice->contractor_code = $daneDokumentu['contractor_code'];
                $invoice->contractor_nip_prefix = $daneDokumentu['contractor_nip_prefix'];
                $invoice->contractor_nip = $daneDokumentu['contractor_nip'];
                $invoice->contractor_city = $daneDokumentu['contractor_city'];
                $invoice->contractor_address = $daneDokumentu['contractor_address'];
                $invoice->contractor_post_code = $daneDokumentu['contractor_post_code'];
                $invoice->total_net = $daneDokumentu['total_net'];
                $invoice->total_gross = $daneDokumentu['total_gross'];
                $invoice->total_net_kurs = $daneDokumentu['total_net_kurs'];
                $invoice->total_gross_kurs = $daneDokumentu['total_gross_kurs'];
                $invoice->podtyp = $daneDokumentu['podtyp'];
                $invoice->currency = $daneDokumentu['currency'];
                $invoice->currency_id = $idWaluty;
                $invoice->kurs_waluty = $daneDokumentu['kurs_waluty'];
                $invoice->kurs_z_dnia = $daneDokumentu['kurs_z_dnia'];
                $invoice->total_vat = $daneDokumentu['razemVATDoVAT'];
                $invoice->DeklRok = $daneDokumentu['DeklRok'];
                $invoice->DeklMiesiac = $daneDokumentu['DeklMiesiac'];
                $invoice->RozliczacVat7 = $daneDokumentu['RozliczacVat7'];

                if(!is_null($daneDokumentu['DeklRok']) && !is_null($daneDokumentu['DeklMiesiac']))
                {
                    $invoice->DeklData = Carbon::create($daneDokumentu['DeklRok'], $daneDokumentu['DeklMiesiac'], 1, 0, 0, 0)->startOfDay();
                }
                else
                {
                    $invoice->DeklData = null;
                }

                $invoice->ocr_dane = (is_null($daneDokumentu['ocr_dane']) ? "" : $daneDokumentu['ocr_dane']);

                if($daneDokumentu['rejestr_id'] != 0)
                {

                    $slownikVAT = SlownikiRejestru::where('erp_id', $daneDokumentu['rejestr_id'])->where('customer_id', $customer->id)->first();

                    if(!$slownikVAT)
                    {
                        throw new Exception('Brak slownika rejestru: '.$daneDokumentu['rejestr_id'], 1);
                    }

                    $invoice->typGlownyRejestr = $slownikVAT->id;
                    $invoice->typVATRejestr = ($slownikVAT->value == 1 || $slownikVAT->value == 2 ? $slownikVAT->id : 0);
                    $invoice->typEDRejestr = ($slownikVAT->value == 34 || $slownikVAT->value == 35 ? $slownikVAT->id : 0);
                }
                else
                {
                    $invoice->typGlownyRejestr = 0;
                    $invoice->typVATRejestr = 0;
                    $invoice->typEDRejestr = 0;
                }

                if(!is_null($invoice->deleted_at))
                {
                    $invoice->deleted_at = NULL;
                }

                $invoice->save();

                if($czyDodajJakoNowy) $this->service->backupInvoice($invoice, true);
                else $this->service->backupInvoice($invoice);


                if(in_array($invoice->pochodzenieDokumentu, [PochodzenieDokumentu::Kancelaria, PochodzenieDokumentu::Optima])) 
                {
                    $key = (string)$invoice->erp_id.'|'.$daneDokumentu['type'].'|'.$daneDokumentu['base_name'].'|'.$invoice->podtyp.'|'.$invoice->id;
                }
                else
                {
                    if($invoice->pochodzenieDokumentu != $daneDokumentu['DokPochodzenie'] && ($daneDokumentu['DokPochodzenie'] == 1)) // naprawcze dla zepsutych dokumentów w kancelarii które w kancelari mają pochodzenie kancelaria a są tak naprawdę eszok
                    {
                        $key = (string)$invoice->erp_id.'|'.$daneDokumentu['type'].'|'.$daneDokumentu['base_name'].'|'.$invoice->podtyp.'|'.$invoice->id;
                    }
                    else
                    {
                        $key = (string)$invoice->id.'|'.$daneDokumentu['type'].'|'.$daneDokumentu['base_name'].'|'.$invoice->podtyp.'|'.$invoice->id;
                    }
                }

                if($czyDodajJakoNowy) array_push($result['created'], $key);
                else array_push($result['updated'], $key);

                InvoiceJPK::where('customer_id', $customer->id)->where('invoice_id', $invoice->id)->delete();
                foreach ($daneDokumentu['JpkList'] as $JpkList)
                {
                    $slownikJPK = JPKSlownik::where('erp_id', $JpkList['JOptimaID']);
                    if($JpkList['JTyp'] == JPKRodzaj::JPK_FA)
                    {
                        $slownikJPK = $slownikJPK->where('DeA_TypGlowny', 0)->where('DeA_JPKDostepnyFA', 1);
                    }
                    if($JpkList['JTyp'] == JPKRodzaj::JPK_V7)
                    {
                        $slownikJPK = $slownikJPK->whereIn('DeA_TypGlowny', [1,2,3]);
                    }
                    if($JpkList['JTyp'] == JPKRodzaj::JPK_VAT)
                    {
                        $slownikJPK = $slownikJPK->where('DeA_JPKDostepnyVAT', 1)->where('DeA_Typ', 5)->where('DeA_Format', '!=', 5);
                    }

                    $slownikJPK = $slownikJPK->where('customer_id', $customer->id)->first();

                    if($slownikJPK)
                    {
                        $newJPK = new InvoiceJPK();
                        $newJPK->rodzaj_jpk = $JpkList['JTyp'];
                        $newJPK->customer_id = $customer->id;
                        $newJPK->invoice_id = $invoice->id;
                        $newJPK->jpk_slownik_id = $slownikJPK->id;
                        $newJPK->format = $slownikJPK->DeA_Format;

                        if($newJPK->rodzaj_jpk == JPKRodzaj::JPK_FA)
                        {
                            if($newJPK->format == 1) $newJPK->wartosc_tekst = $JpkList['JWartosc'];
                            if($newJPK->format == 2) $newJPK->wartosc_liczba = str_replace(",", ".", $JpkList['JWartosc']);
                            if($newJPK->format == 3)
                            {
                                $JPKWartoscLista = JPKSlownikElem::where('customer_id', $customer->id)->where('DAE_DeAId', $JpkList['JOptimaID'])->where('jpk_slownik_id', $slownikJPK->id)->where('DAE_Wartosc', $JpkList['JWartosc'])->first();

                                if(!$JPKWartoscLista) continue;

                                $newJPK->wartosc_lista_id = $JPKWartoscLista->id;
                            }
                            if($newJPK->format == 4) $newJPK->wartosc_data = $JpkList['JWartosc'];
                        }
                        if($newJPK->rodzaj_jpk == JPKRodzaj::JPK_V7)
                        {
                            $newJPK->format = null;
                        }
                        if($newJPK->rodzaj_jpk == JPKRodzaj::JPK_VAT)
                        {
                            $newJPK->wartosc_liczba = str_replace(",", ".", $JpkList['JWartosc']);
                        }
                        $newJPK->save();
                    }
                }

            }
            catch (\Exception $e)
            {
                array_push($result['errors'], array('key'=> 'INFO', 'value' => 'Błąd odbioru dokumentu (ERP ID: '.(is_null($daneDokumentu['OptimaID'] ?? null) ? 'Brak' : $daneDokumentu['OptimaID']).', Kancelaria ID: '.(is_null($daneDokumentu['kancelaria_id'] ?? null) ? 'Brak' : $daneDokumentu['kancelaria_id']).', ESZOK ID: '.(is_null($daneDokumentu['EszokId'] ?? null) ? 'Brak' : $daneDokumentu['EszokId']).'): '.$e->getMessage()));
                continue;
            }
        }
        

        logger()->debug('ZAKONCZONO przetwarzanie updateOrCreateInvoices(Request $request): '.print_r($result, true));
        return $result;
    }
}
