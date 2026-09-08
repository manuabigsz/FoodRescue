export const statusLabels = {
    reserved: 'Reservado',
    shipping_quotation: 'Cotando transporte',
    carrier_selected: 'Transportadora selecionada',
    buyer_managed: 'Transporte pelo comprador',
    waiting_payment: 'Aguardando pagamento',
    funded: 'Pagamento em custódia',
    ready_for_pickup: 'Pronto para coleta',
    in_transit: 'Em trânsito',
    delivered: 'Entregue',
    proof_pending: 'Comprovação pendente',
    completed: 'Concluído',
    cancelled: 'Cancelado',
    expired: 'Expirado',
};

export const roleLabels = { producer: 'Produtor', buyer: 'Comprador', carrier: 'Transportadora', ngo: 'ONG' };

export const surplusStatusLabels = {
    open: 'Aberto',
    reserved: 'Reservado',
    sold: 'Vendido',
    donated: 'Doado',
    cancelled: 'Cancelado',
    expired: 'Expirado',
};

export const offerStatusLabels = { pending: 'Aguardando resposta', accepted: 'Aceita', rejected: 'Recusada', expired: 'Expirada', withdrawn: 'Retirada' };

export const userStatusLabels = { active: 'Ativo', blocked: 'Bloqueado' };

export const unitLabels = { kg: 'Quilogramas (kg)', t: 'Toneladas (t)', box: 'Caixas', unit: 'Unidades' };

export const logisticsLabels = { buyer_pickup: 'Retirada pelo destinatário', third_party_carrier: 'Transportadora contratada' };

export const settingLabels = {
    shipping_quotation_timeout_minutes: 'Prazo de cotação de frete',
    payment_timeout_minutes: 'Prazo de pagamento',
};

export const timelineSteps = [
    { label: 'Reservado', matches: ['reserved'] },
    { label: 'Transporte definido', matches: ['shipping_quotation', 'carrier_selected', 'buyer_managed'] },
    { label: 'Aguardando pagamento', matches: ['waiting_payment'] },
    { label: 'Pagamento em custódia', matches: ['funded'] },
    { label: 'Pronto para coleta', matches: ['ready_for_pickup'] },
    { label: 'Em trânsito', matches: ['in_transit'] },
    { label: 'Entregue', matches: ['delivered'] },
    { label: 'Comprovação pendente', matches: ['proof_pending'], donationOnly: true },
    { label: 'Concluído', matches: ['completed'] },
];

export const nextActions = {
    reserved: 'Defina a logística da operação para seguir para o pagamento.',
    shipping_quotation: 'Aguardando cotações das transportadoras.',
    carrier_selected: 'Transportadora selecionada. O próximo passo é o pagamento em custódia.',
    buyer_managed: 'Transporte por conta do destinatário. O próximo passo é o pagamento em custódia.',
    waiting_payment: 'Deposite o valor em FRUSD para que a custódia seja criada on-chain.',
    funded: 'Pagamento em custódia. O produtor precisa liberar o lote para coleta.',
    ready_for_pickup: 'Lote liberado. A transportadora deve confirmar a coleta.',
    in_transit: 'Carga em trânsito. O destinatário confirma o recebimento na entrega.',
    delivered: 'Entrega confirmada. A liquidação encerra a operação.',
    proof_pending: 'A organização beneficiária precisa emitir o Proof of Rescue.',
    completed: 'Operação concluída e liquidada on-chain.',
    cancelled: 'Operação cancelada.',
    expired: 'Operação expirada por falta de pagamento no prazo.',
};
