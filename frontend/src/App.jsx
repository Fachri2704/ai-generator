import { useEffect, useRef, useState } from "react";
import LoginPage from "./pages/LoginPage";
import RegisterPage from "./pages/RegisterPage";
import AiToCompro from "./pages/AiToCompro";

const USER_STORAGE_KEY = "ai-landing-auth-user";

const getStoredUser = () => {
  try {
    const raw = localStorage.getItem(USER_STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
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

export default function App() {
  const [view, setView] = useState(() => (getStoredUser() ? "generator" : "login"));
  const [user, setUser] = useState(getStoredUser);
  const [generatorMode, setGeneratorMode] = useState("landing");

  const [form, setForm] = useState({
    company_name: "",
    product: "",
    audience: "",
    tone: "profesional",
    main_offer: "",
    price_note: "",
    bonus: "",
    urgency: "",
    cta: "Daftar Sekarang",
    contact: "",
    brand_color: "",
  });

  const [loginForm, setLoginForm] = useState({
    email: "",
    password: "",
  });

  const [registerForm, setRegisterForm] = useState({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
  });

  const [loading, setLoading] = useState(false);
  const [html, setHtml] = useState("");
  const [authLoading, setAuthLoading] = useState(false);
  const [authErr, setAuthErr] = useState("");
  const [authMsg, setAuthMsg] = useState("");
  const [limitPopup, setLimitPopup] = useState({ open: false, retryIn: null });
  const [errorPopup, setErrorPopup] = useState({ open: false, message: "" });
  const [previewHeight, setPreviewHeight] = useState(520);
  const formCardRef = useRef(null);

  const onChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });
  const onLoginChange = (e) => setLoginForm({ ...loginForm, [e.target.name]: e.target.value });
  const onRegisterChange = (e) => setRegisterForm({ ...registerForm, [e.target.name]: e.target.value });

  const setLoggedInUser = (nextUser) => {
    setUser(nextUser);
    localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(nextUser));
  };

  const logout = async () => {
    setAuthErr("");
    setAuthMsg("");

    try {
      await fetch("/api/logout", { method: "POST" });
    } finally {
      localStorage.removeItem(USER_STORAGE_KEY);
      setUser(null);
      setView("login");
      setGeneratorMode("landing");
      setAuthMsg("Kamu sudah logout.");
    }
  };

  const register = async (e) => {
    e.preventDefault();
    setAuthLoading(true);
    setAuthErr("");
    setAuthMsg("");

    try {
      const res = await fetch("/api/register", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(registerForm),
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(readErrorMessage(data));
      }

      setAuthMsg("Registrasi berhasil. Silakan login.");
      setRegisterForm({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
      });
      setView("login");
    } catch (e2) {
      setAuthErr(String(e2.message || e2));
    } finally {
      setAuthLoading(false);
    }
  };

  const login = async (e) => {
    e.preventDefault();
    setAuthLoading(true);
    setAuthErr("");
    setAuthMsg("");

    try {
      const res = await fetch("/api/login", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(loginForm),
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(readErrorMessage(data));
      }

      setLoggedInUser(data.user);
      setView("generator");
      setLoginForm({ email: "", password: "" });
      setAuthMsg("Login berhasil.");
    } catch (e2) {
      setAuthErr(String(e2.message || e2));
    } finally {
      setAuthLoading(false);
    }
  };

  const generate = async () => {
    setLoading(true);
    setLimitPopup({ open: false, retryIn: null });
    setErrorPopup({ open: false, message: "" });
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
      const message = String(e.message || e);
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
  }, [view, user, form]);

  return (
    <div className="pageWrap">
      <header className="siteHeader">
        <div className="siteHeaderInner">
          <button
            className="navTitle border-0 bg-transparent p-0 text-left"
            type="button"
            onClick={() => setView(user ? "generator" : "login")}
          >
            AI Website Generator
          </button>
          <div className="navLinks">
            {user ? (
              <>
                <span>{user.name}</span>
                <span className="navDivider">|</span>
                <button className="navLink" type="button" onClick={logout}>
                  Logout
                </button>
              </>
            ) : (
              <>
                <button className="navLink" type="button" onClick={() => setView("login")}>
                  Login
                </button>
                <span className="navDivider">|</span>
                <button className="navLink" type="button" onClick={() => setView("register")}>
                  Register
                </button>
              </>
            )}
          </div>
        </div>
      </header>

      <main className="mainWrap">
        <div className="shell">
          <div className="navBar">
            {view === "generator" && user ? (
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  className="btnSmall"
                  onClick={() => setGeneratorMode("landing")}
                  disabled={generatorMode === "landing"}
                >
                  AiToLandingPage
                </button>
                <button
                  type="button"
                  className="btnSmall"
                  onClick={() => setGeneratorMode("company")}
                  disabled={generatorMode === "company"}
                >
                  AiToCompro
                </button>
              </div>
            ) : (
              <p className="tagline">Generate clean, ready to use landing pages in seconds</p>
            )}
          </div>

          {authMsg && <div className="mt-3 text-[12px] md:text-[13px] text-green-700">{authMsg}</div>}
          {authErr && <div className="mt-3 text-[12px] md:text-[13px] text-red-600">{authErr}</div>}

          {view === "login" ? (
            <LoginPage
              form={loginForm}
              onChange={onLoginChange}
              onSubmit={login}
              loading={authLoading}
            />
          ) : null}

          {view === "register" ? (
            <RegisterPage
              form={registerForm}
              onChange={onRegisterChange}
              onSubmit={register}
              loading={authLoading}
            />
          ) : null}

          {view === "generator" && user && generatorMode === "landing" ? (
            <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
              <section className="lg:col-span-5">
                <div ref={formCardRef} className="card cardSoft">
                  <div className="grid gap-3">
                    <Input
                      label="Nama Brand / Bisnis"
                      name="company_name"
                      value={form.company_name}
                      onChange={onChange}
                      placeholder="Contoh: Tahfidz Scalev Academy"
                    />
                    <Input
                      label="Produk / Program"
                      name="product"
                      value={form.product}
                      onChange={onChange}
                      placeholder="Contoh: Kelas Tahfidz Online 90 Hari"
                    />
                    <Input
                      label="Target Audiens"
                      name="audience"
                      value={form.audience}
                      onChange={onChange}
                      placeholder="Contoh: Orang tua muslim usia 25-45 tahun"
                    />
                    <Input
                      label="Headline Penawaran Utama"
                      name="main_offer"
                      value={form.main_offer}
                      onChange={onChange}
                      placeholder="Contoh: Hafal 2 Juz dalam 90 Hari dengan Metode Terarah"
                    />
                    <Input
                      label="Harga / Promo Singkat"
                      name="price_note"
                      value={form.price_note}
                      onChange={onChange}
                      placeholder="Contoh: Mulai 299rb, diskon 40% hingga Jumat"
                    />
                    <Input
                      label="Bonus Produk (Opsional)"
                      name="bonus"
                      value={form.bonus}
                      onChange={onChange}
                      placeholder="Contoh: E-book murojaah + mentoring mingguan"
                    />
                    <Input
                      label="Elemen Urgency (Opsional)"
                      name="urgency"
                      value={form.urgency}
                      onChange={onChange}
                      placeholder="Contoh: Kuota batch ini tinggal 17 kursi"
                    />

                    <div>
                      <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">
                        Tone
                      </label>
                      <select name="tone" value={form.tone} onChange={onChange} className="input">
                        <option value="profesional">Professional</option>
                        <option value="formal">Formal</option>
                        <option value="santai">Casual</option>
                      </select>
                    </div>

                    <Input
                      label="CTA Utama"
                      name="cta"
                      value={form.cta}
                      onChange={onChange}
                      placeholder="Contoh: Ambil Promo Sekarang"
                    />
                    <Input
                      label="Kontak (Opsional)"
                      name="contact"
                      value={form.contact}
                      onChange={onChange}
                      placeholder="Contoh: WhatsApp 08xxxxxxxxxx"
                    />
                    <Input
                      label="Warna Brand (Opsional)"
                      name="brand_color"
                      value={form.brand_color}
                      onChange={onChange}
                      placeholder="Contoh: #0A7A4B"
                    />

                    <button onClick={generate} disabled={loading} className="btnPrimary mt-1">
                      {loading ? "Generating..." : "Generate Landing Page"}
                    </button>

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
                  <div className="text-[13px] md:text-[14px] font-semibold text-[#111111]">Preview</div>
                  <div className="previewCard" style={{ height: `${previewHeight}px` }}>
                    <iframe title="preview" className="h-full w-full" srcDoc={html} />
                  </div>
                </div>
              </section>
            </div>
          ) : null}

          {view === "generator" && user && generatorMode === "company" ? <AiToCompro /> : null}
        </div>
      </main>

      {loading && generatorMode === "landing" ? (
        <div className="loadingOverlay" role="status" aria-live="polite" aria-label="Sedang generate landing page">
          <div className="loadingPopup">
            <span className="loadingSpinner" aria-hidden="true" />
            <div className="loadingTitle">Sedang Generate Landing Page</div>
            <div className="loadingText">AI lagi nyusun struktur, copywriting, dan HTML kamu...</div>
          </div>
        </div>
      ) : null}

      {limitPopup.open && generatorMode === "landing" ? (
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

      {errorPopup.open && generatorMode === "landing" ? (
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

function Input({ label, ...props }) {
  return (
    <div>
      <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">{label}</label>
      <input {...props} className="input" />
    </div>
  );
}
