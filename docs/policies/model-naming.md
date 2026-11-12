# Naming, notices and attribution for models we derive

Status: draft for the fictional company's legal reviewers · Owner: platform team · Review: 2027-02-11

Every fine-tune and every distilled student carries its base's license. As of the license text versions recorded in `config/ai-licenses.php`:

- **Attribution.** Where a license asks for a notice when a product uses its models, the public summary of the assistant (`resources/help/account/about-the-assistant.md`, the help article "About the assistant") carries it. `ai:feature-card` writes the line from the license record, so a model added to a route brings its notice with it.
- **Naming.** Some community licenses ask that a derived model that is distributed or made available carry the base family's name at the start of its name. The models here are served inside Ledgerly's own service and are named by what they do (`ledgerly-billing-ft`), and each record says `hosted-service`. The day a derived model or adapter is given to anyone else, change its record to `distributed-weights`, read the naming clause in the text version you reviewed, and rename it before it leaves.
- **Open-source licenses** (Apache 2.0, MIT) ask that the license text and notices travel with the weights. The registry keeps each version's base and its hash (`registry:verify`), and the license text is in the base model's repository at the revision it is pinned to.
