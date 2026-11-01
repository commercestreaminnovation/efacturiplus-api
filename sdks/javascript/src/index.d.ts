export const DEFAULT_URL: string;
export const VERSION: string;

export interface InvoiceLine {
  name: string;
  quantity: number;
  price: number;
  cota_tva?: number;
  um?: string;
  mentions?: string;
}

export interface InvoiceRequest {
  serie_factura: string;
  data_factura: string;
  products: InvoiceLine[];
  'Codul fiscal'?: string;
  numar_facura?: number;
  scadenta_date?: string;
  currency_name?: string;
  external_id?: string;
  customer_name?: string;
  customer_cui?: string;
  customer_reg_com?: string;
  customer_address?: string;
  customer_loc?: string;
  customer_jud?: string;
  customer_country?: string;
  customer_postcode?: string;
  customer_email?: string;
  customer_telephone?: string;
  pret_fara_tva?: boolean;
  mentiuni?: string;
  draft?: boolean;
  scutire_tva?: 'taxare-inversa' | 'servicii-ue' | 'livrare-ue' | 'export' | 'scutit-292' | 'neimpozabil' | 'alta-scutire';
  motiv_scutire?: string;
}

export interface InvoiceResponse {
  success: true;
  duplicate?: boolean;
  message?: string;
  invoice_id: number;
  invoice: { serie: string; numar: number | null; label: string | null; status: string; data: string; scadenta: string | null; total: number; tva: number };
  efactura_status: string;
  efactura: { status: string; label: string; index_incarcare: string | null; errors: string[] };
  pdf_url: string | null;
}

export class ApiError extends Error {
  status: number;
  code: string | null;
  body: Record<string, unknown>;
  errors: Record<string, string[]>;
}

export class Client {
  constructor(apiKey: string, options?: { baseUrl?: string; timeout?: number; retries?: number });
  account(): Promise<any>;
  createInvoice(invoice: InvoiceRequest): Promise<InvoiceResponse>;
  getInvoice(invoiceId: number): Promise<InvoiceResponse>;
  findInvoice(serie: string, numar: number, cui?: string | null): Promise<InvoiceResponse>;
  listInvoices(filters?: Record<string, string | number>): Promise<any>;
  listCustomers(filters?: Record<string, string | number>): Promise<any>;
  listProducts(filters?: Record<string, string | number>): Promise<any>;
  sendToSpv(invoiceId: number): Promise<InvoiceResponse>;
  storno(invoiceId: number): Promise<InvoiceResponse>;
  createAccount(account: Record<string, unknown>): Promise<any>;
  getAccount(cui: string): Promise<any>;
  reissueActivation(cui: string): Promise<any>;
  downloadPdf(pdfUrl: string): Promise<Buffer>;
}

export function verifyWebhook(body: string | Buffer, signature: string, secret: string, options?: { tolerance?: number; now?: number }): boolean;
