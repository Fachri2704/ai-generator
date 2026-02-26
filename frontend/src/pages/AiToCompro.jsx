import { useEffect, useRef, useState } from "react";
import "./AiToCompro.css";

const defaultForm = {
  company_name: "",
  industry: "",
  tagline: "",
  company_overview: "",
  vision: "",
  mission: "",
  services: "",
  target_market: "",
  unique_value: "",
  achievements: "",
  portfolio: "",
  team_info: "",
  contact_email: "",
  contact_phone: "",
  address: "",
  social_links: "",
  cta: "Hubungi Tim Kami",
  tone: "profesional",
  brand_color: "",
};

const readErrorMessage = (data) => {
  if (!data) return "Terjadi kesalahan.";
  if (typeof data.message === "string" && data.message.trim() !== "") return data.message;
  if (data.errors && typeof data.errors === "object") {
    const firstField = Object.values(data.errors)[0];
    if (Array.isArray(firstField) && firstField[0]) return firstField[0];
  }
  return "Terjadi kesalahan.";
};

const isQuotaLimitError = (message) => {
  const text = String(message || "").toLowerCase();
  return (
    text.includes("quota exceeded") ||
    text.includes("rate limit") ||
    text.includes("exceeded your current quota") ||
    text.includes("too many requests") ||
    text.includes("retry in")
  );
};

const parseRetrySeconds = (message) => {
  const text = String(message || "");
  const match = text.match(/retry in\s+([\d.]+)s/i);
  if (!match?.[1]) return null;
  const sec = Math.ceil(Number(match[1]));
  return Number.isFinite(sec) ? sec : null;
};

function AiToCompro() {
  const [form, setForm] = useState(defaultForm);
  const [loading, setLoading] = useState(false);
  const [html, setHtml] = useState("");
  const [limitPopup, setLimitPopup] = useState({ open: false, retryIn: null });
  const [errorPopup, setErrorPopup] = useState({ open: false, message: "" });
  const [previewHeight, setPreviewHeight] = useState(520);

  const formCardRef = useRef(null);

  const onChange = (event) => {
    const { name, value } = event.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const generate = async () => {
    setLoading(true);
    setLimitPopup({ open: false, retryIn: null });
    setErrorPopup({ open: false, message: "" });
    setHtml("");

    try {
      const res = await fetch("/api/generate-company-profile", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(form),
      });

      const contentType = res.headers.get("content-type") || "";
      if (!contentType.includes("application/json")) {
        const text = await res.text();
        throw new Error(
          `Server mengembalikan non-JSON (${res.status}). ` +
            "Pastikan backend /api berjalan dan tidak mengirim HTML. " +
            `Cuplikan: ${text.slice(0, 120)}`
        );
      }

      const data = await res.json();
      if (!res.ok) {
        throw new Error(readErrorMessage(data));
      }

      setHtml(data.html || "");
    } catch (error) {
      const message = String(error.message || error);
      if (isQuotaLimitError(message)) {
        setLimitPopup({
          open: true,
          retryIn: parseRetrySeconds(message),
        });
      } else {
        setErrorPopup({ open: true, message });
      }
    } finally {
      setLoading(false);
    }
  };

  const syncPreviewWithForm = () => {
    if (window.innerWidth < 1024) {
      setPreviewHeight(360);
      return;
    }

    const card = formCardRef.current;
    if (!card) return;

    const nextHeight = Math.max(420, Math.floor(card.getBoundingClientRect().height));
    setPreviewHeight(Math.min(nextHeight, 1500));
  };

  useEffect(() => {
    syncPreviewWithForm();

    if (!formCardRef.current || typeof ResizeObserver === "undefined") {
      const onResize = () => syncPreviewWithForm();
      window.addEventListener("resize", onResize);
      return () => window.removeEventListener("resize", onResize);
    }

    const observer = new ResizeObserver(() => syncPreviewWithForm());
    observer.observe(formCardRef.current);
    window.addEventListener("resize", syncPreviewWithForm);

    return () => {
      observer.disconnect();
      window.removeEventListener("resize", syncPreviewWithForm);
    };
  }, [form]);

  return (
    <div className="aitcWrap">
      <div className="aitcHead">
        <h1 className="aitcTitle">AiToCompro</h1>
        <p className="aitcSubtitle">
          Generate company profile website dengan struktur profesional dari brief bisnis kamu.
        </p>
      </div>

      <div className="aitcGrid">
        <section className="aitcFormCol">
          <div ref={formCardRef} className="aitcCard aitcFormCard">
            <div className="aitcForm">
              <InputField
                label="Nama Perusahaan"
                name="company_name"
                value={form.company_name}
                onChange={onChange}
                placeholder="Contoh: PT Maju Bersama Teknologi"
              />
              <InputField
                label="Industri"
                name="industry"
                value={form.industry}
                onChange={onChange}
                placeholder="Contoh: Konsultan IT"
              />
              <InputField
                label="Tagline (Opsional)"
                name="tagline"
                value={form.tagline}
                onChange={onChange}
                placeholder="Contoh: Solusi Digital yang Bertumbuh Bersama Bisnis Anda"
              />
              <TextareaField
                label="Ringkasan Perusahaan"
                name="company_overview"
                value={form.company_overview}
                onChange={onChange}
                placeholder="Jelaskan profil singkat perusahaan, sejarah, dan fokus utama."
              />
              <TextareaField
                label="Visi (Opsional)"
                name="vision"
                value={form.vision}
                onChange={onChange}
                placeholder="Visi perusahaan."
                rows={3}
              />
              <TextareaField
                label="Misi (Opsional)"
                name="mission"
                value={form.mission}
                onChange={onChange}
                placeholder="Misi perusahaan (boleh poin-poin)."
                rows={4}
              />
              <TextareaField
                label="Layanan Utama"
                name="services"
                value={form.services}
                onChange={onChange}
                placeholder="Daftar layanan utama yang ditawarkan."
                rows={4}
              />
              <TextareaField
                label="Target Market (Opsional)"
                name="target_market"
                value={form.target_market}
                onChange={onChange}
                placeholder="Segmentasi pasar utama."
                rows={3}
              />
              <TextareaField
                label="Unique Value Proposition (Opsional)"
                name="unique_value"
                value={form.unique_value}
                onChange={onChange}
                placeholder="Keunggulan utama dibanding kompetitor."
                rows={3}
              />
              <TextareaField
                label="Pencapaian (Opsional)"
                name="achievements"
                value={form.achievements}
                onChange={onChange}
                placeholder="Contoh: 200+ klien, sertifikasi, award, dsb."
                rows={3}
              />
              <TextareaField
                label="Portfolio / Proyek (Opsional)"
                name="portfolio"
                value={form.portfolio}
                onChange={onChange}
                placeholder="Contoh proyek penting yang pernah ditangani."
                rows={3}
              />
              <TextareaField
                label="Info Tim (Opsional)"
                name="team_info"
                value={form.team_info}
                onChange={onChange}
                placeholder="Ringkasan kekuatan tim atau struktur inti."
                rows={3}
              />
              <InputField
                label="Email Kontak (Opsional)"
                name="contact_email"
                type="email"
                value={form.contact_email}
                onChange={onChange}
                placeholder="hello@perusahaan.com"
              />
              <InputField
                label="No. Telepon / WhatsApp (Opsional)"
                name="contact_phone"
                value={form.contact_phone}
                onChange={onChange}
                placeholder="08xxxxxxxxxx"
              />
              <TextareaField
                label="Alamat (Opsional)"
                name="address"
                value={form.address}
                onChange={onChange}
                placeholder="Alamat kantor utama"
                rows={2}
              />
              <InputField
                label="Sosial Media / Link (Opsional)"
                name="social_links"
                value={form.social_links}
                onChange={onChange}
                placeholder="Instagram, LinkedIn, website pendukung, dll"
              />

              <div>
                <label className="aitcLabel">Tone</label>
                <select name="tone" value={form.tone} onChange={onChange} className="aitcInput">
                  <option value="profesional">Professional</option>
                  <option value="formal">Formal</option>
                  <option value="santai">Casual</option>
                </select>
              </div>

              <InputField
                label="Teks CTA Utama"
                name="cta"
                value={form.cta}
                onChange={onChange}
                placeholder="Contoh: Konsultasi Sekarang"
              />
              <InputField
                label="Warna Brand (Opsional)"
                name="brand_color"
                value={form.brand_color}
                onChange={onChange}
                placeholder="Contoh: #1456D9"
              />

              <button type="button" onClick={generate} disabled={loading} className="aitcButton">
                {loading ? "Generating..." : "Generate Company Profile"}
              </button>

            </div>
          </div>
        </section>

        <section className="aitcPreviewCol">
          <div className="aitcCard">
            <div className="aitcOutputHead">
              <h2 className="aitcOutputTitle">Output</h2>
              <button
                type="button"
                className="aitcCopy"
                disabled={!html}
                onClick={() => navigator.clipboard.writeText(html)}
              >
                Copy HTML
              </button>
            </div>

            <textarea
              className="aitcCode"
              value={html}
              readOnly
              placeholder="HTML company profile hasil generate akan muncul di sini"
            />
          </div>

          <div className="aitcPreviewWrap">
            <div className="aitcPreviewLabel">Preview</div>
            <div className="aitcPreviewCard" style={{ height: `${previewHeight}px` }}>
              <iframe title="preview-company-profile" className="aitcIframe" srcDoc={html} />
            </div>
          </div>
        </section>
      </div>

      {loading ? (
        <div className="loadingOverlay" role="status" aria-live="polite" aria-label="Sedang generate company profile">
          <div className="loadingPopup">
            <span className="loadingSpinner" aria-hidden="true" />
            <div className="loadingTitle">Sedang Generate Company Profile</div>
            <div className="loadingText">AI lagi nyusun layout, copy, dan struktur website profil perusahaan kamu...</div>
          </div>
        </div>
      ) : null}

      {limitPopup.open ? (
        <div className="alertOverlay" role="alertdialog" aria-modal="true" aria-label="Batas penggunaan API">
          <div className="alertPopup">
            <div className="alertTitle">Batas API Tercapai</div>
            <div className="alertText">
              Kuota request AI kamu lagi habis sementara.
              {limitPopup.retryIn ? ` Coba lagi sekitar ${limitPopup.retryIn} detik.` : " Coba lagi beberapa saat."}
            </div>
            <button type="button" className="btnSmall mt-4" onClick={() => setLimitPopup({ open: false, retryIn: null })}>
              Oke, Mengerti
            </button>
          </div>
        </div>
      ) : null}

      {errorPopup.open ? (
        <div className="alertOverlay" role="alertdialog" aria-modal="true" aria-label="Generate gagal">
          <div className="alertPopup">
            <div className="alertTitle">Generate Gagal</div>
            <div className="alertText">{errorPopup.message}</div>
            <button
              type="button"
              className="btnSmall mt-4"
              onClick={() => setErrorPopup({ open: false, message: "" })}
            >
              Tutup
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function InputField({ label, ...props }) {
  return (
    <div>
      <label className="aitcLabel">{label}</label>
      <input {...props} className="aitcInput" />
    </div>
  );
}

function TextareaField({ label, rows = 4, ...props }) {
  return (
    <div>
      <label className="aitcLabel">{label}</label>
      <textarea {...props} rows={rows} className="aitcTextarea" />
    </div>
  );
}

export default AiToCompro;
