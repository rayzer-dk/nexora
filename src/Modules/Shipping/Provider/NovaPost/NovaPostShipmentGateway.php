<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\NovaPost;

use RuntimeException;

final readonly class NovaPostShipmentGateway
{
    public function __construct(
        private NovaPostTransport $transport,
        private string $senderRef,
        private string $contactSenderRef,
        private string $citySenderRef,
        private string $senderAddressRef,
        private string $senderPhone = '',
        private string $payerType = 'Sender',
        private string $paymentMethod = 'NonCash',
        private string $labelUrlTemplate = '',
    ) {}

    public function configured(): bool
    {
        foreach([$this->senderRef,$this->contactSenderRef,$this->citySenderRef,$this->senderAddressRef] as $v)if(trim($v)==='')return false;
        return trim($this->transport->apiKey())!=='';
    }

    /** @param array<string,mixed> $input @return array{external_id:string,tracking_number:string,label_url:?string} */
    public function create(array $input): array
    {
        if(!$this->configured())throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.sender_profile_not_configured'));
        foreach(['recipient_name','recipient_phone','city_ref','address_ref','description'] as $key)if(trim((string)($input[$key]??''))==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.shipment_data_incomplete', ['field' => $key]));
        $recipient=$this->recipient((string)$input['recipient_name'],(string)$input['recipient_phone'],(string)($input['recipient_email']??''));
        $weight=max(0.1,(float)($input['weight_kg']??0.1));$cost=max(1,(float)($input['cost_uah']??1));
        $properties=[
            'PayerType'=>$this->payerType,'PaymentMethod'=>$this->paymentMethod,'DateTime'=>date('d.m.Y'),'CargoType'=>'Cargo',
            'Weight'=>$this->decimal($weight),'ServiceType'=>'WarehouseWarehouse','SeatsAmount'=>'1','Description'=>mb_substr(trim(strip_tags((string)$input['description'])),0,100),
            'Cost'=>$this->decimal($cost),'CitySender'=>$this->citySenderRef,'Sender'=>$this->senderRef,'SenderAddress'=>$this->senderAddressRef,'ContactSender'=>$this->contactSenderRef,
            'SendersPhone'=>$this->phone((string)($input['sender_phone']??$this->senderPhone)),'CityRecipient'=>(string)$input['city_ref'],'Recipient'=>$recipient['recipient_ref'],
            'RecipientAddress'=>(string)$input['address_ref'],'ContactRecipient'=>$recipient['contact_ref'],'RecipientsPhone'=>$this->phone((string)$input['recipient_phone']),
        ];
        if($properties['SendersPhone']==='')unset($properties['SendersPhone']);
        if(isset($input['cod_uah'])&&(float)$input['cod_uah']>0){$properties['BackwardDeliveryData']=[['PayerType'=>'Recipient','CargoType'=>'Money','RedeliveryString'=>$this->decimal((float)$input['cod_uah'])]];}
        $rows=$this->transport->rows($this->transport->call('InternetDocument','save',$properties));$row=$rows[0]??null;
        if(!is_array($row))throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.created_document_missing'));
        $ref=$this->scalar($row['Ref']??null);$number=$this->scalar($row['IntDocNumber']??null);
        if($ref===''||$number==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.document_references_missing'));
        return ['external_id'=>$ref,'tracking_number'=>$number,'label_url'=>null];
    }

    public function cancel(string $externalId): void
    {
        if(trim($externalId)==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.document_ref_missing'));
        $this->transport->call('InternetDocument','delete',['DocumentRefs'=>[$externalId]]);
    }

    /** @return array<string,mixed> */
    public function track(string $trackingNumber,string $phone=''): array
    {
        $doc=['DocumentNumber'=>$trackingNumber];if($this->phone($phone)!=='')$doc['Phone']=$this->phone($phone);
        $rows=$this->transport->rows($this->transport->call('TrackingDocument','getStatusDocuments',['Documents'=>[$doc]]));
        return is_array($rows[0]??null)?$rows[0]:[];
    }

    private function recipient(string $name,string $phone,string $email): array
    {
        $parts=preg_split('/\s+/u',trim($name))?:[];$last=(string)array_shift($parts);$first=(string)array_shift($parts);$middle=trim(implode(' ',$parts));
        if($first===''){$first=$last;$last='';}
        $props=['FirstName'=>$first,'LastName'=>$last,'MiddleName'=>$middle,'Phone'=>$this->phone($phone),'Email'=>trim($email),'CounterpartyType'=>'PrivatePerson','CounterpartyProperty'=>'Recipient'];
        $rows=$this->transport->rows($this->transport->call('Counterparty','save',$props));$row=$rows[0]??null;if(!is_array($row))throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.recipient_not_created'));
        $recipientRef=$this->scalar($row['Ref']??null);$contactRef='';
        $contact=$row['ContactPerson']??null;if(is_array($contact)){$data=$contact['data']??$contact;if(is_array($data)&&is_array($data[0]??null))$contactRef=$this->scalar($data[0]['Ref']??null);}
        if($recipientRef===''||$contactRef==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.recipient_references_missing'));
        return ['recipient_ref'=>$recipientRef,'contact_ref'=>$contactRef];
    }

    public function labelConfigured(): bool { return trim($this->labelUrlTemplate)!==''; }

    public function labelPdf(string $ref): string
    {
        if(trim($this->labelUrlTemplate)==='')throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.label_endpoint_not_configured'));
        $url=str_replace(['{ref}','{apiKey}'],[rawurlencode($ref),rawurlencode($this->transport->apiKey())],$this->labelUrlTemplate);
        if(!str_starts_with($url,'https://'))throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.label_endpoint_https_required'));
        return $this->transport->download($url);
    }
    private function phone(string $v):string{return preg_replace('/\D+/','',$v)??'';}
    private function decimal(float $v):string{return rtrim(rtrim(number_format($v,2,'.',''),'0'),'.');}
    private function scalar(mixed $v):string{return is_scalar($v)?trim((string)$v):'';}
}
