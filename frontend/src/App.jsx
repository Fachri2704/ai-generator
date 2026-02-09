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
    <div className="pageWrap">
        <div className="navBar">
          <div className="navTitle">AI to Landing Page Generator</div>
          <div className="navLinks">
            <button className="navLink" type="button">
              Login
            </button>
            <span className="navDivider">|</span>
            <button className="navLink" type="button">
              Register
            </button>
          </div>
        </div>

        <p className="tagline">
          Generate clean, ready to use landing pages in seconds
        </p>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
          <section className="lg:col-span-5">
            <div className="card cardSoft">
              <div className="grid gap-3">
                <Input
                  label="Company Name"
                  name="company_name"
                  value={form.company_name}
                  onChange={onChange}
                  placeholder="Company Name"
                />
                <Input
                  label="Product / Services"
                  name="product"
                  value={form.product}
                  onChange={onChange}
                  placeholder="Example: AI-powered landing page generator"
                />
                <Input
                  label="Audience Target"
                  name="audience"
                  value={form.audience}
                  onChange={onChange}
                  placeholder="Example: Parents, Adults"
                />

                <div>
                  <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">
                    Tone
                  </label>
                  <select
                    name="tone"
                    value={form.tone}
                    onChange={onChange}
                  className="input"
                  >
                    <option value="profesional">Professional</option>
                    <option value="formal">Formal</option>
                    <option value="santai">Casual</option>
                  </select>
                </div>

                <Input
                  label="CTA Button"
                  name="cta"
                  value={form.cta}
                  onChange={onChange}
                  placeholder="Example: Contact Us, Buy Now"
                />
                <Input
                  label="Contact (optional)"
                  name="contact"
                  value={form.contact}
                  onChange={onChange}
                  placeholder=""
                />
                <Input
                  label="Color Brand (optional)"
                  name="brand_color"
                  value={form.brand_color}
                  onChange={onChange}
                  placeholder=""
                />

                <button
                  onClick={generate}
                  disabled={loading}
                  className="btnPrimary mt-1"
                >
                  {loading ? "Generating..." : "Generate Landing Page"}
                </button>

                {err && <div className="text-[12px] md:text-[13px] text-red-600">{err}</div>}
              </div>
            </div>
          </section>

          <section className="lg:col-span-7 flex flex-col gap-6">
            <div className="card">
              <div className="flex items-center justify-between">
                <h2 className="text-[14px] md:text-[16px] font-semibold text-[#111111]">Output</h2>
                <button
                  className="btnSmall"
                  disabled={!html}
                  onClick={() => navigator.clipboard.writeText(html)}
                >
                  Copy HTML
                </button>
              </div>

              <div className="mt-3 grid gap-3">
                <textarea
                  className="codeBox"
                  value={html}
                  readOnly
                  placeholder="Your generated HTML code will appear here"
                />
              </div>
            </div>

            <div className="grid gap-2">
              <div className="text-[13px] md:text-[14px] font-semibold text-[#111111]">
                Preview
              </div>
              <div className="previewCard">
                <iframe
                  title="preview"
                  className="h-full w-full"
                  srcDoc={html}
                />
              </div>
            </div>
          </section>
        </div>
    </div>
  );
}

function Input({ label, ...props }) {
  return (
    <div>
      <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">{label}</label>
      <input {...props} className="input" />
    </div>
  );
}
