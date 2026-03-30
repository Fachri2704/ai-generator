import "./MainMenuPage.css";

function MainMenuPage({ onSelect }) {
  return (
    <div className="mainMenuPage">
      <div className="mainMenuHero">
        <div className="mainMenuHeroInner">
          <h1 className="mainMenuTitle">Website mana yang akan kamu buat hari ini?</h1>
          <p className="mainMenuSubtitle">Pilih Sekarang!!</p>
        </div>

        <div className="mainMenuGrid">
          <article className="mainMenuCard">
            <div>
              <h2 className="mainMenuCardTitle">Klik untuk mulai generate landing page</h2>
              <p className="mainMenuCardText">
                Lengkapi form untuk membuat landing page profesional secara otomatis dan siap
                digunakan dalam hitungan detik.
              </p>
            </div>

            <button type="button" className="mainMenuButton" onClick={() => onSelect("landing")}>
              Landing Page
            </button>
          </article>

          <article className="mainMenuCard">
            <div>
              <h2 className="mainMenuCardTitle">Klik untuk mulai generate company profile</h2>
              <p className="mainMenuCardText">
                Lengkapi form dan buat company profile profesional yang siap digunakan untuk
                memperkenalkan bisnis kamu.
              </p>
            </div>

            <button type="button" className="mainMenuButton" onClick={() => onSelect("company")}>
              Company Profile
            </button>
          </article>
        </div>
      </div>
    </div>
  );
}

export default MainMenuPage;