# frozen_string_literal: true

# Client pentru API-ul public eFacturiPlus. Ruby 3.0+, fara dependente.
require 'json'
require 'net/http'
require 'openssl'
require 'uri'

module EFacturiPlus
  DEFAULT_URL = 'https://www.efacturiplus.ro/app/api/parteneri/v1'
  VERSION = '1.1.0'

  class ApiError < StandardError
    attr_reader :status, :code, :body

    def initialize(message, status: 0, code: nil, body: {})
      super(message)
      @status = status
      @code = code
      @body = body
    end

    def errors
      body['errors'] || {}
    end
  end

  class Client
    def initialize(api_key, base_url: DEFAULT_URL, timeout: 25, retries: 3)
      unless base_url.start_with?('https://') || base_url.match?(%r{\Ahttp://(127\.0\.0\.1|localhost)(:|/|\z)})
        raise ApiError, 'Adresa API trebuie să fie https: cheia nu se trimite necriptat.'
      end

      @api_key = api_key
      @base_url = base_url.sub(%r{/+\z}, '')
      @timeout = timeout
      @retries = retries
    end

    def account = request(:get, '/cont')
    def create_invoice(invoice) = request(:post, '/facturi', invoice)
    def get_invoice(invoice_id) = request(:get, "/facturi?invoice_id=#{Integer(invoice_id)}")

    def find_invoice(serie, numar, cui: nil)
      query = { serie: serie, numar: numar }
      query[:cui] = cui if cui
      request(:get, "/facturi?#{URI.encode_www_form(query)}")
    end

    def list_invoices(**filters) = request(:get, "/facturi/lista?#{URI.encode_www_form(filters)}")
    def list_customers(**filters) = request(:get, "/clienti?#{URI.encode_www_form(filters)}")
    def list_products(**filters) = request(:get, "/produse?#{URI.encode_www_form(filters)}")
    def send_to_spv(invoice_id) = request(:post, "/facturi/#{Integer(invoice_id)}/spv", {})
    def storno(invoice_id) = request(:post, "/facturi/#{Integer(invoice_id)}/storno", {})
    def create_account(account) = request(:post, '/conturi', account)
    def get_account(cui) = request(:get, "/conturi/#{URI.encode_www_form_component(cui)}")
    def reissue_activation(cui) = request(:post, "/conturi/#{URI.encode_www_form_component(cui)}/activare", {})

    # Descarca PDF-ul din pdf_url. Intoarce continutul binar.
    def download_pdf(pdf_url)
      a = URI(pdf_url)
      b = URI(@base_url)
      raise ApiError, 'Linkul PDF nu este de pe adresa API.' unless [a.scheme, a.host, a.port] == [b.scheme, b.host, b.port]

      response = transmit(:get, a, nil, false)
      unless response.code == '200' && response.body.start_with?('%PDF')
        raise ApiError.new("PDF indisponibil (HTTP #{response.code}).", status: response.code.to_i)
      end

      response.body
    end

    private

    def request(method, path, body = nil)
      attempt = 0
      loop do
        begin
          response = transmit(method, URI(@base_url + path), body, true)
        rescue SystemCallError, SocketError, Timeout::Error, IOError, OpenSSL::SSL::SSLError => e
          # Fara raspuns nu stim daca s-a scris ceva: se repeta doar citirile.
          if method == :get && attempt < @retries
            sleep(0.5 * (2**attempt))
            attempt += 1
            next
          end
          raise ApiError, e.message
        end
        status = response.code.to_i
        if [429, 503].include?(status) && attempt < @retries
          sleep(response['Retry-After'] ? response['Retry-After'].to_f : 0.5 * (2**attempt))
          attempt += 1
          next
        end
        data = begin
          JSON.parse(response.body.to_s)
        rescue JSON::ParserError
          {}
        end
        data = {} unless data.is_a?(Hash)
        raise ApiError.new(data['message'] || "HTTP #{status}", status: status, code: data['code'], body: data) unless data['success']

        return data
      end
    end

    def transmit(method, uri, body, with_key)
      request = (method == :post ? Net::HTTP::Post : Net::HTTP::Get).new(uri)
      request['Accept'] = 'application/json, application/pdf'
      request['Content-Type'] = 'application/json'
      request['User-Agent'] = "efacturiplus-ruby/#{VERSION}"
      request['X-Api-Key'] = @api_key if with_key
      request.body = JSON.generate(body) unless body.nil?
      Net::HTTP.start(uri.host, uri.port, use_ssl: uri.scheme == 'https', open_timeout: @timeout, read_timeout: @timeout) do |http|
        http.request(request)
      end
    end
  end

  # body e corpul brut, signature antetul X-Signature.
  def self.verify_webhook(body, signature, secret, tolerance: 300, now: Time.now.to_i)
    match = /\At=(\d+),v1=([0-9a-f]{64})\z/.match(signature.to_s)
    return false if match.nil? || (now - match[1].to_i).abs > tolerance

    expected = OpenSSL::HMAC.hexdigest('SHA256', secret, "#{match[1]}.#{body}")
    OpenSSL.fixed_length_secure_compare(expected, match[2])
  end
end
