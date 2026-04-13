import { useEffect, useRef, useState } from "react";
import LoginPage from "./pages/LoginPage";
import RegisterPage from "./pages/RegisterPage";
import AiToCompro from "./pages/AiToCompro";
import MainMenuPage from "./pages/MainMenuPage";

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
    text.includes("retry in") ||
    text.includes("resource exhausted") ||
    text.includes("server ai sedang padat") ||
    text.includes("request ke ai sedang terlalu banyak") ||
    text.includes("layanan ai sedang cooldown")
  );
};

const parseRetrySeconds = (message) => {
  const text = String(message || "");
  const patterns = [
    /retry in\s+([\d.]+)s/i,
    /sekitar\s+([\d.]+)\s*detik/i,
    /([\d.]+)\s*detik\s+lagi/i,
  ];

  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (!match?.[1]) continue;
    const sec = Math.ceil(Number(match[1]));
    if (Number.isFinite(sec)) return sec;
  }

  return null;
};

const LANDING_REQUIRED_FIELDS = [
  ["company_name", "Nama Brand / Bisnis"],
  ["product", "Produk / Program"],
  ["audience", "Target Audiens"],
  ["main_offer", "Headline Penawaran Utama"],
  ["price_note", "Harga / Promo Singkat"],
  ["tone", "Tone"],
  ["cta", "CTA Utama"],
];

const findMissingFields = (values, requiredFields) =>
  requiredFields
    .filter(([key]) => String(values[key] ?? "").trim() === "")
    .map(([, label]) => label);

export default function App() {
  const [view, setView] = useState(() => (getStoredUser() ? "main-menu" : "login"));
  const [user, setUser] = useState(getStoredUser);
  const [generatorMode, setGeneratorMode] = useState("landing");
  const [successPopup, setSuccessPopup] = useState({
    open: false,
    title: "",
    message: "",
    nextView: null,
    nextUser: null,
  });

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
  const [validationPopup, setValidationPopup] = useState({ open: false, message: "" });
  const [errorPopup, setErrorPopup] = useState({ open: false, message: "" });
  const [generationNotice, setGenerationNotice] = useState("");
  const [previewHeight, setPreviewHeight] = useState(520);
  const formCardRef = useRef(null);
  const codeBoxRef = useRef(null);

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

  const goToView = (nextView) => {
    setAuthMsg("");
    setAuthErr("");
    setView(nextView);
  };

  const closeSuccessPopup = () => {
    if (successPopup.nextUser) {
      setLoggedInUser(successPopup.nextUser);
    }

    if (successPopup.nextView) {
      setView(successPopup.nextView);
    }

    setSuccessPopup({
      open: false,
      title: "",
      message: "",
      nextView: null,
      nextUser: null,
    });
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
      setSuccessPopup({
        open: true,
        title: "Registrasi Berhasil",
        message: "Akun kamu berhasil dibuat. Klik lanjut untuk masuk ke halaman login.",
        nextView: "login",
        nextUser: null,
      });
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

      setAuthMsg("");
      setLoginForm({ email: "", password: "" });
      setSuccessPopup({
        open: true,
        title: "Login Berhasil",
        message: "Kamu berhasil login. Klik lanjut untuk masuk ke main menu.",
        nextView: "main-menu",
        nextUser: data.user,
      });
    } catch (e2) {
      setAuthErr(String(e2.message || e2));
    } finally {
      setAuthLoading(false);
    }
  };

  const generate = async () => {
    const missingFields = findMissingFields(form, LANDING_REQUIRED_FIELDS);
    if (missingFields.length > 0) {
      setLimitPopup({ open: false, retryIn: null });
      setErrorPopup({ open: false, message: "" });
      setValidationPopup({
        open: true,
        message: `Lengkapi field wajib berikut dulu: ${missingFields.join(", ")}.`,
      });
      return;
    }

    setLoading(true);
    setLimitPopup({ open: false, retryIn: null });
    setValidationPopup({ open: false, message: "" });
    setErrorPopup({ open: false, message: "" });
    setGenerationNotice("");
    const shouldForceRefresh = html.trim() !== "";
    setHtml("");

    try {
      const res = await fetch("/api/generate", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ ...form, force_refresh: shouldForceRefresh }),
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
      setGenerationNotice(typeof data.notice === "string" ? data.notice : "");
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

  useEffect(() => {
    if (!codeBoxRef.current) return;
    codeBoxRef.current.scrollTop = 0;
    codeBoxRef.current.scrollLeft = 0;
  }, [html]);

  return (
    <div className="pageWrap">
      <header className="siteHeader">
        <div className="siteHeaderInner">
          <button
            className="navTitle border-0 bg-transparent p-0 text-left"
            type="button"
            onClick={() => goToView(user ? "main-menu" : "login")}
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
                <button className="navLink" type="button" onClick={() => goToView("login")}>
                  Login
                </button>
                <span className="navDivider">|</span>
                <button className="navLink" type="button" onClick={() => goToView("register")}>
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
              <div className="generatorIntroBlock">
                <p className="generatorIntro">
                  {generatorMode === "landing"
                    ? "Generate landing pages in seconds, lebih cepat, lebih mudah, dan siap pakai dengan AI."
                    : "Generate company profile website dengan struktur professional dari brief bisnis kamu"}
                </p>
                <div className="modeSwitch">
                  <button
                    type="button"
                    className={`modeButton ${generatorMode === "landing" ? "modeButtonActive" : ""}`}
                    onClick={() => setGeneratorMode("landing")}
                  >
                    AiToLandingPage
                  </button>
                  <button
                    type="button"
                    className={`modeButton ${generatorMode === "company" ? "modeButtonActive" : ""}`}
                    onClick={() => setGeneratorMode("company")}
                  >
                    AiToCompro
                  </button>
                </div>
              </div>
            ) : view === "main-menu" && user ? (
              <div />
            ) : (
              <p className="tagline">Generate clean, ready to use landing pages in seconds</p>
            )}
          </div>

          {authMsg && (
            <div className="mt-3 text-[12px] md:text-[13px]" style={{ color: "#11EF36" }}>
              {authMsg}
            </div>
          )}
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

          {view === "main-menu" && user ? (
            <MainMenuPage
              onSelect={(mode) => {
                setAuthMsg("");
                setAuthErr("");
                setGeneratorMode(mode);
                setView("generator");
              }}
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
                      {loading ? "Generating..." : html ? "Generate Ulang Landing Page" : "Generate Landing Page"}
                    </button>

                  </div>
                </div>
              </section>

              <section className="lg:col-span-7 flex flex-col gap-6">
                <div className="card">
                  <div className="flex items-center justify-between">
                    <h2 className="text-[14px] md:text-[16px] font-semibold text-[#111111]">Output</h2>
                    <button
                      className="btnCopy"
                      disabled={!html}
                      onClick={() => navigator.clipboard.writeText(html)}
                    >
                      Copy HTML
                    </button>
                  </div>

                  {generationNotice ? (
                    <div
                      className="mt-3 rounded-xl border px-3 py-2 text-[12px] md:text-[13px]"
                      style={{ borderColor: "#D6E4FF", background: "#F5F9FF", color: "#1849A9" }}
                    >
                      {generationNotice}
                    </div>
                  ) : null}

                  <div className="mt-3 grid gap-3">
                    <textarea
                      ref={codeBoxRef}
                      className="codeBox"
                      value={html}
                      readOnly
                      placeholder="Your generated HTML code will appear here"
                    />
                  </div>
                </div>

                <div className="grid gap-2">
                  <div className="previewLabel">Preview</div>
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

      {validationPopup.open && generatorMode === "landing" ? (
        <div className="alertOverlay" role="alertdialog" aria-modal="true" aria-label="Form belum lengkap">
          <div className="alertPopup">
            <div className="alertTitle">Form Belum Lengkap</div>
            <div className="alertText">{validationPopup.message}</div>
            <button
              type="button"
              className="btnSmall mt-4"
              onClick={() => setValidationPopup({ open: false, message: "" })}
            >
              Oke
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

      {successPopup.open ? (
        <div className="alertOverlay" role="alertdialog" aria-modal="true" aria-label="Aksi berhasil">
          <div className="alertPopup">
            <div className="alertTitle">{successPopup.title}</div>
            <div className="alertText">{successPopup.message}</div>
            <button type="button" className="btnSmall mt-4" onClick={closeSuccessPopup}>
              Lanjut
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
