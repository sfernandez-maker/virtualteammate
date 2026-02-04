// Enqueue scripts for VA filtering (exclude only /blog/ and ignore footer/social icons)
function va_filter_scripts() {
    // Safety: do not run in admin
    if (is_admin()) {
        return;
    }

    // ✅ Exclude ONLY the /blog/ URL (and /blog without trailing slash)
    // Matches example.com/blog/ exactly, not example.com/blog/post-name/
    global $wp;
    $request_path = isset($wp->request) ? trim($wp->request, "/") : "";
    if ($request_path === "blog" || is_home()) {
        return;
    }

    wp_enqueue_script("jquery");

    $inline_js = <<<'JS'
jQuery(document).ready(function($) {
    console.log("=== FILTER SYSTEM LOADED (FAST AUTO-LOAD) ===");

    var isAutoLoading = false;
    var loadAttempts = 0;
    var maxAttempts = 30;
    var searchDebounce = null;

    //--------------------------------------
    // FIX: Only department change resets skills
    //--------------------------------------
    $("#department-filter").change(function() {
        updateSkillsFilter();
        loadAttempts = 0;
        filterProfiles();
    });

    // Other filters should NOT reset skills dropdown
    $(".va-filter").not("#department-filter").change(function() {
        loadAttempts = 0;
        filterProfiles();
    });

    //--------------------------------------
    // NAME FILTER: Instant with smart auto-load
    //--------------------------------------
    $("#name-filter").on("keyup", function() {
        clearTimeout(searchDebounce);
        loadAttempts = 0;
        
        // Debounce: wait 200ms after user stops typing
        searchDebounce = setTimeout(function() {
            filterWithAutoLoad();
        }, 200);
    });

    $("#reset-filters").click(function() {
        $(".va-filter").val("");
        $("#name-filter").val("");
        $("#skills-filter").html('<option value="">Select Primary Skill Set</option>');
        isAutoLoading = false;
        loadAttempts = 0;
        clearTimeout(searchDebounce);
        filterProfiles();
    });

    //--------------------------------------
    // Monitor for Load More clicks (manual)
    //--------------------------------------
    $(document).on('click', '#loadmore, .loadmore, button[id*="loadmore"], a[id*="loadmore"]', function(e) {
        if (isAutoLoading) {
            console.log("⏸ Auto-loading in progress");
            return;
        }
        
        console.log("👆 Manual Load More");
        setTimeout(filterProfiles, 300);
    });

    //--------------------------------------
    // Monitor DOM for new posts
    //--------------------------------------
    var targetNode = document.querySelector('.elementor-posts-container, .elementor-loop-container, .elementor-grid');
    if (targetNode) {
        var observer = new MutationObserver(function(mutations) {
            var nodesAdded = false;
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) nodesAdded = true;
            });
            
            if (nodesAdded && isAutoLoading) {
                console.log("📦 New posts loaded");
                setTimeout(filterWithAutoLoad, 150);
            } else if (nodesAdded) {
                setTimeout(filterProfiles, 150);
            }
        });
        
        observer.observe(targetNode, { childList: true, subtree: true });
    }

    //--------------------------------------
    // UPDATE SKILLS DROPDOWN BY DEPARTMENT
    //--------------------------------------
    function updateSkillsFilter() {
        var department = $("#department-filter").val();
        var skillsFilter = $("#skills-filter");

        skillsFilter.html('<option value="">Select Primary Skill Set</option>');
        if (!department) return;

        var skills = {
            "healthcare": ["medical administrative support","medical receptionist","medical biller","medical scribe","healthcare assistant"],
            "administrative": ["administrative assistant","executive assistant","personal assistant","office assistant"],
            "business-intelligence": ["hubspot specialist","bi developer","data analyst","quality assurance analyst"],
            "customer-service": ["client services representative","customer service specialist","receptionist"],
            "finance": ["bookkeeper","accountant","billing coordinator","accounts payable specialist"],
            "dental": ["dental assistant","dental biller","dental receptionist"],
            "marketing": ["graphic designer","marketing coordinator","social media manager","e-commerce specialist","marketing manager"],
            "sales": ["sales representative"]
        };

        if (skills[department]) {
            skills[department].forEach(function(skill) {
                skillsFilter.append(
                    "<option value='" + skill.toLowerCase() + "'>" +
                    skill.replace(/\b\w/g, c => c.toUpperCase()) +
                    "</option>"
                );
            });
        }
    }

    //--------------------------------------
    // KEYWORD MAP
    //--------------------------------------
    var keywordMap = {
        "medical administrative support": ["admin","administrative","emr","ehr","charting","clinical support","documentation","drchrono","eclinicalworks","telehealth","intake","patient records","scheduling","appointment","ehr system","emr system"],
        "medical receptionist": ["front desk","receptionist","inbound calls","appointment","scheduling","calendar","patient communication","call handling","front office","check-in"],
        "medical biller": ["billing","claims","insurance verification","prior authorization","payment posting","rcm","icd","cpt","denials","collections","superbill","charge entry","claims processing"],
        "medical scribe": ["scribe","documentation","transcription","chart notes","soap notes","medical dictation","physician support","emr updating"],
        "healthcare assistant": ["vitals","patient support","medical assistant","clinical assistant","room preparation","patient intake","clinical tasks"],
        "administrative assistant": ["executive support","calendar management","inbox management","scheduling","admin tasks","travel planning","meeting coordination","minutes","expense reports"],
        "executive assistant": ["executive support","ceo support","calendar management","travel planning","confidential","high level admin"],
        "personal assistant": ["personal assistant","errands","personal scheduling","personal admin"],
        "office assistant": ["office assistant","clerical","filing","reception","office support","mailing"],
        "hubspot specialist": ["hubspot","crm","marketing automation","workflows","hubspot crm"],
        "bi developer": ["business intelligence","bi","power bi","tableau","data warehouse","etl","sql","dashboards"],
        "data analyst": ["data analysis","excel","sql","data cleaning","reporting","power bi","tableau","insights"],
        "quality assurance analyst": ["qa","quality assurance","testing","test cases","automation testing","manual testing"],
        "client services representative": ["client services","client support","client communication","account coordination","client relations"],
        "customer service specialist": ["customer service","csr","support","tickets","chat support","call center"],
        "receptionist": ["receptionist","front desk","phone handling","greeting","visitor"],
        "bookkeeper": ["bookkeeping","quickbooks","xero","ledgers","reconciliation","bank reconciliation","accounts","journal entries"],
        "accountant": ["accountant","tax","financial statements","accounts","cpa","accounting"],
        "billing coordinator": ["billing coordinator","invoice","billing","payment posting","accounts receivable"],
        "accounts payable specialist": ["accounts payable","ap","vendor payments","invoice processing","payments"],
        "dental assistant": ["dental assistant","chairside","dental chair","dental procedures","sterilization"],
        "dental biller": ["dental billing","dental claims","predetermination","insurance verification","cdr billing"],
        "dental receptionist": ["dental receptionist","dental front desk","patient scheduling","dental appointments"],
        "graphic designer": ["graphic design","photoshop","illustrator","canva","ad creative","visuals"],
        "marketing coordinator": ["marketing coordinator","campaigns","campaign management","marketing operations"],
        "social media manager": ["social media","instagram","facebook","linkedin","tiktok","reels","content calendar","scheduling tools"],
        "e-commerce specialist": ["shopify","woocommerce","amazon","product listing","order management","ecommerce"],
        "marketing manager": ["marketing strategy","marketing manager","brand","campaigns","analytics"],
        "sales representative": ["sales","lead generation","cold calling","closing","account management"]
    };

    //--------------------------------------
    // Helper: escape regex special chars
    //--------------------------------------
    function escapeRegex(s) {
        return s.replace(/[-\/\\^$*+?.()|[\]{}]/g, "\\$&");
    }

    //--------------------------------------
    // Get profile items (exclude footer/social icons)
    //--------------------------------------
    function getProfileItems() {
        var $all = $(".elementor-loop-item, .e-loop-item, .elementor-grid-item");
        return $all.filter(function() {
            var $el = $(this);
            if ($el.closest("footer, .site-footer, .elementor-location-footer").length) return false;
            if ($el.closest(".elementor-widget-social-icons, .elementor-social-icons").length) return false;
            return true;
        });
    }

    //--------------------------------------
    // Get Load More button
    //--------------------------------------
    function getLoadMoreButton() {
        return $('#loadmore, .loadmore, button[id*="loadmore"], a[id*="loadmore"]').filter(':visible').first();
    }

    //--------------------------------------
    // SMART AUTO-LOAD FILTER
    //--------------------------------------
    function filterWithAutoLoad() {
        var nameSearch = $("#name-filter").val().toLowerCase().trim();
        var visible = filterProfiles();
        
        // Only auto-load if searching by name AND no results found
        if (nameSearch && visible === 0 && loadAttempts < maxAttempts) {
            var $btn = getLoadMoreButton();
            
            if ($btn.length) {
                loadAttempts++;
                isAutoLoading = true;
                // HIDE the message while we're still searching
                $("#no-results").hide();
                console.log("🔄 Auto-loading... (" + loadAttempts + "/" + maxAttempts + ")");
                $btn[0].click();
            } else {
                console.log("❌ No more posts to load");
                isAutoLoading = false;
                // Show "no virtual teammates found" when auto-load exhausted
                $("#no-results").show();
            }
        } else {
            isAutoLoading = false;
            if (loadAttempts >= maxAttempts) {
                console.log("⚠️ Max attempts reached");
                // Show message when max attempts reached
                $("#no-results").show();
            }
        }
    }

    //--------------------------------------
    // MAIN FILTERING LOGIC
    //--------------------------------------
    function filterProfiles() {
        var department = $("#department-filter").val();
        var skills = $("#skills-filter").val();
        var country = $("#country-filter").val();
        var nameSearch = $("#name-filter").val().toLowerCase().trim();

        var visibleCount = 0;
        var $items = getProfileItems();

        $items.each(function() {
            var $item = $(this);
            var text = $item.text().toLowerCase();

            //--------------------------------------
            // NAME FILTER
            //--------------------------------------
            var matchesName = true;
            if (nameSearch) {
                var $heading = $item.find("h1, h2, h3, h4, h5, h6, .elementor-heading-title, .elementor-post-title");
                var nameText = $heading.length ? $heading.first().text().toLowerCase() : text;
                matchesName = nameText.indexOf(nameSearch) !== -1;
            }

            //--------------------------------------
            // DEPARTMENT DETECTION
            //--------------------------------------
            var itemDept = "";

            function hasWord(keyword) {
                return new RegExp("\\b" + escapeRegex(keyword) + "\\b").test(text);
            }

            if (hasWord("healthcare")) itemDept = "healthcare";
            if (hasWord("dental")) itemDept = "dental";
            if (hasWord("administrative")) itemDept = "administrative";
            if (hasWord("sales")) itemDept = "sales";
            if (hasWord("marketing")) itemDept = "marketing";
            if (hasWord("finance")) itemDept = "finance";
            if (hasWord("customer service")) itemDept = "customer-service";
            if (hasWord("business intelligence") || hasWord("bi")) itemDept = "business-intelligence";

            var deptList = ["healthcare","dental","administrative","sales","marketing","finance","customer-service","business-intelligence"];
            var found = deptList.filter(d => hasWord(d.replace("-", " ")));

            if (found.length === 1) {
                itemDept = found[0];
            } else if (found.length > 1) {
                itemDept = "mixed";
            }

            //--------------------------------------
            // COUNTRY DETECTION
            //--------------------------------------
            var itemCountry = "";
            if (hasWord("philippines")) itemCountry = "philippines";
            if (hasWord("latin america")) itemCountry = "latin-america";

            //--------------------------------------
            // APPLY FILTERS
            //--------------------------------------
            var show = true;

            if (!matchesName) show = false;
            if (department && itemDept !== department) show = false;
            if (country && itemCountry !== country) show = false;

            //--------------------------------------
            // SKILLS FILTER
            //--------------------------------------
            if (skills) {
                var matched = false;
                var mapped = keywordMap[skills];

                if (mapped && Array.isArray(mapped)) {
                    for (var i = 0; i < mapped.length; i++) {
                        var kw = mapped[i].toString().toLowerCase().trim();
                        if (!kw) continue;
                        var pat = escapeRegex(kw).replace(/\s+/g, "\\s*");
                        var re = new RegExp(pat, "i");
                        if (re.test(text)) {
                            matched = true;
                            break;
                        }
                    }
                } else {
                    var patSkill = escapeRegex(skills).replace(/\s+/g, "\\s*");
                    var reSkill = new RegExp(patSkill, "i");
                    matched = reSkill.test(text);
                }

                if (!matched) show = false;
            }

            //--------------------------------------
            // NEVER SHOW MIXED DEPARTMENTS
            //--------------------------------------
//             if (itemDept === "" || itemDept === "mixed") show = false;

            //--------------------------------------
            // SHOW/HIDE
            //--------------------------------------
            $item.toggle(show);
            if (show) visibleCount++;
        });

        //--------------------------------------
        // LATIN AMERICA "COMING SOON"
        //--------------------------------------
        if (country === "latin-america") {
            $("#coming-soon").show();
            $("#coming-soon p").text("Our Latin American Virtual Assistants will be available soon.");
            getProfileItems().hide();
            $("#no-results").hide();
            return 0;
        } else {
            $("#coming-soon").hide();
        }

        //--------------------------------------
        // NO RESULTS MESSAGE
        //--------------------------------------
        if (visibleCount === 0) {
            $("#no-results").show();
        } else {
            $("#no-results").hide();
        }
        
        if (nameSearch) {
            console.log("✓ " + visibleCount + " matches for '" + nameSearch + "'");
        }
        
        return visibleCount;
    }

    //--------------------------------------
    // FIRST LOAD
    //--------------------------------------
    setTimeout(() => {
        updateSkillsFilter();
        filterProfiles();
    }, 300);
});
JS;

    wp_add_inline_script("jquery", $inline_js);
}
add_action("wp_enqueue_scripts", "va_filter_scripts");