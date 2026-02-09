import { useState } from "react";

export default function App() {
  const [form, setForm] = useState({
    company_name: "",
    product: "",
    audience: "",
    tone: "profesional",
    cta: "Hubungi Kami",
    contact: "",
    brand_color: "",
  });

  const [loading, setLoading] = useState(false);
  const [html, setHtml] = useState("");
  const [err, setErr] = useState("");

  const onChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });

  const generate = async () => {
    setLoading(true);
    setErr("");
    setHtml("");

    try {
      const res = await fetch("/api/generate", {
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
        throw new Error(data?.message || data?.ai?.[0] || "Gagal");
      }

      setHtml(data.html);
    } catch (e) {
      setErr(String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="mx-auto max-w-6xl grid gap-6 lg:grid-cols-2">
        <div className="bg-white rounded-xl shadow p-5">
          <h1 className="text-xl font-semibold">AI Landing Page Generator</h1>
          <p className="text-sm text-gray-600 mt-1">
            Isi data → Generate → dapat HTML + preview
          </p>

          <div className="mt-4 grid gap-3">
            <Input label="Nama PT" name="company_name" value={form.company_name} onChange={onChange} />
            <Input label="Produk/Jasa" name="product" value={form.product} onChange={onChange} />
            <Input label="Target Audiens" name="audience" value={form.audience} onChange={onChange} />

            <div>
              <label className="text-sm font-medium">Tone</label>
              <select name="tone" value={form.tone} onChange={onChange}
                className="mt-1 w-full rounded-lg border p-2">
                <option value="profesional">Profesional</option>
                <option value="formal">Formal</option>
                <option value="santai">Santai</option>
              </select>
            </div>

            <Input label="CTA Button" name="cta" value={form.cta} onChange={onChange} />
            <Input label="Contact (opsional)" name="contact" value={form.contact} onChange={onChange} />
            <Input label="Warna Brand (opsional)" name="brand_color" value={form.brand_color} onChange={onChange} />

            <button
              onClick={generate}
              disabled={loading}
              className="mt-2 rounded-lg bg-black text-white py-2 disabled:opacity-60"
            >
              {loading ? "Generating..." : "Generate Landing Page"}
            </button>

            {err && <div className="text-sm text-red-600">{err}</div>}
          </div>
        </div>

        <div className="bg-white rounded-xl shadow p-5">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold">Output</h2>
            <button
              className="text-sm underline disabled:opacity-50"
              disabled={!html}
              onClick={() => navigator.clipboard.writeText(html)}
            >
              Copy HTML123
            </button>
          </div>

          <div className="mt-3 grid gap-4">
            <textarea
              className="w-full h-56 rounded-lg border p-2 font-mono text-xs"
              value={html}
              readOnly
              placeholder="HTML akan muncul di sini..."
            />

            <div className="rounded-lg border overflow-hidden">
              <div className="px-3 py-2 text-sm bg-gray-100 border-b">Preview</div>
              <iframe
                title="preview"
                className="w-full h-80"
                srcDoc={html}
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function Input({ label, ...props }) {
  return (
    <div>
      <label className="text-sm font-medium">{label}</label>
      <input {...props} className="mt-1 w-full rounded-lg border p-2" />
    </div>
  );
}
